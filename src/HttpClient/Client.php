<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Crab\Contracts\HttpClient\Client as ClientContract;
use Crab\Contracts\HttpClient\CookieJar as CookieJarContract;
use Crab\Contracts\HttpClient\Response as ResponseContract;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Http\Multipart;
use Crab\Http\Request;
use Crab\Http\Stream;
use Crab\Http\Uri;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * An HTTP client that does not hold the worker up: while a request waits
 * for its answer, the worker serves other requests. It speaks HTTP/2
 * with servers that offer it over an encrypted connection, and HTTP/1.1
 * with the rest.
 *
 *     $http = new Client();
 *     $user = $http->get('https://api.example.com/users/7')->throw()->json();
 *
 *     $stripe = new Client('https://api.stripe.com/v1', headers: ['Authorization' => "Bearer {$key}"]);
 *     $charge = $stripe->timeout(5.0)->post('/charges', json: ['amount' => 500]);
 *
 * Connections are kept and used again: one for all the requests to an
 * HTTP/2 server, up to a number per server otherwise, where a request
 * beyond that waits for one. Redirects are followed, and a compressed
 * answer is handed over as what it says.
 *
 * No answer's body may be longer than a limit, 64 MiB unless said
 * otherwise, which is checked as it arrives. A download goes straight
 * into a file:
 *
 *     $http->timeout(1200)->request('GET', $url, sink: '/srv/roms/7.zip', maxBytes: 4 << 30);
 *
 * In a test, FakeClient is this client with replies written in advance
 * in place of the network.
 */
class Client implements ClientContract
{
    /**
     * Methods that may be made twice without doing anything twice.
     */
    private const REPEATABLE = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'];

    /**
     * The most bytes of body an answer may have unless a call says otherwise: 64 MiB.
     */
    public const MAX_BYTES = 67_108_864;

    private float $timeout;
    private int $retries;
    private float $retryDelay;
    private ?CookieJarContract $jar;
    private readonly Transport $transport;

    /**
     * @param string $baseUrl What a path given to a request is added to: "https://api.example.com/v1".
     * @param array<string, string|list<string>> $headers Sent with every request, unless the request says otherwise.
     * @param float $timeout Seconds a whole call may take, redirects, retries and their pauses included.
     * @param float $connectTimeout Seconds connecting to a server may take.
     * @param int $redirects How many redirects are followed for one request; 0 to return the redirect itself.
     * @param int $connections How many requests may be on their way to one server at once.
     * @param array<string, mixed> $tls PHP's "ssl" context options for encrypted connections, such as ['cafile' => '/path/ca.pem']. The server's certificate is verified unless this says not to.
     * @param string|null $proxy A proxy every request goes through: "http://proxy.example:8080", with "user:password@" before the host when it asks.
     * @param CookieJarContract|null $cookies Keeps the cookies servers set and sends them back. None unless given, since one jar would be shared by everyone the client works for.
     * @param int $retries How many more times a request is tried when it could not be made or the server asked for it later; see retry().
     * @param float $retryDelay Seconds waited before the second try, doubled before each one after.
     * @param bool $http2 Whether HTTP/2 is used with servers that offer it over an encrypted connection.
     * @param int $maxBytes The most bytes of body an answer may have, unless request() is given its own maxBytes:.
     * @param Transport|null $transport @internal What carries the requests; the network when left out.
     * @param ClockInterface|null $clock Reads Retry-After dates against this time; the real time when left out.
     */
    public function __construct(
        private readonly string $baseUrl = '',
        private readonly array $headers = [],
        float $timeout = 30.0,
        float $connectTimeout = 5.0,
        private readonly int $redirects = 5,
        int $connections = 8,
        array $tls = [],
        ?string $proxy = null,
        ?CookieJarContract $cookies = null,
        int $retries = 0,
        float $retryDelay = 0.2,
        bool $http2 = true,
        ?Transport $transport = null,
        private readonly ?ClockInterface $clock = null,
        private readonly int $maxBytes = self::MAX_BYTES,
    ) {
        if (
            $timeout <= 0
            || $maxBytes < 0
            || $connectTimeout <= 0
            || $redirects < 0
            || $connections < 1
            || $retries < 0
            || $retryDelay < 0
        ) {
            throw new \LogicException(
                'An HTTP client needs timeouts above zero, at least one connection per server, and numbers of redirects, retries and bytes that are not negative.',
            );
        }
        $this->timeout = $timeout;
        $this->retries = $retries;
        $this->retryDelay = $retryDelay;
        $this->jar = $cookies;
        $this->transport = $transport ?? new Network($connectTimeout, $connections, $tls, $proxy, $http2);
    }

    public function timeout(float $seconds): static
    {
        if ($seconds <= 0) {
            throw new \LogicException('A timeout is a number of seconds above zero.');
        }
        // The copy carries its requests the same way, over the same connections.
        $new = clone $this;
        $new->timeout = $seconds;
        return $new;
    }

    public function retry(int $times, float $delay = 0.2): static
    {
        if ($times < 0 || $delay < 0) {
            throw new \LogicException('retry() takes a number of times and a delay in seconds, neither below zero.');
        }
        $new = clone $this;
        $new->retries = $times;
        $new->retryDelay = $delay;
        return $new;
    }

    public function cookies(CookieJarContract $jar): static
    {
        $new = clone $this;
        $new->jar = $jar;
        return $new;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->send($request, null, new Receiving($this->maxBytes));
    }

    public function request(
        string $method,
        string $url,
        array $query = [],
        array $headers = [],
        mixed $json = null,
        array $form = [],
        array $files = [],
        string|StreamInterface|null $body = null,
        ?Cancellation $cancellation = null,
        string|StreamInterface|null $sink = null,
        ?int $maxBytes = null,
        bool $decode = true,
    ): ResponseContract {
        if ($maxBytes !== null && $maxBytes < 0) {
            throw new \LogicException('maxBytes: is a number of bytes, not below zero.');
        }
        if (is_string($sink)) {
            $folder = dirname($sink);
            if ($sink === '' || str_ends_with($sink, '/') || is_dir($sink)) {
                throw new \LogicException(
                    "sink: is the file the body is written to, and \"{$sink}\" is not a file's path.",
                );
            }
            if (!is_dir($folder) || !is_writable($folder)) {
                throw new \RuntimeException(
                    "The body cannot be written to {$sink}: {$folder} is not a folder this process may write in.",
                );
            }
        } elseif ($sink !== null && !$sink->isWritable()) {
            throw new \LogicException('sink: is a stream the body is written to, and the one given cannot be written.');
        }
        if ((($json !== null) + ($form !== [] || $files !== []) + ($body !== null)) > 1) {
            throw new \LogicException(
                'A request has one body: give json:, a form (form: and files:) or body:, not several.',
            );
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $url) !== 1) {
            if ($this->baseUrl === '') {
                $shown = substr($url, 0, strcspn($url, '?#'));
                throw new \LogicException(
                    "\"{$shown}\" is not a whole URL, and this client has no base URL to add it to. Give \"https://...\", or make the client with a base URL.",
                );
            }
            $url = rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
        }
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $headers = [...$this->headers, ...$headers];
        $names = array_change_key_case($headers, CASE_LOWER);
        if ($json !== null) {
            $body = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $type = 'application/json';
        } elseif ($files !== []) {
            [$body, $type] = Multipart::write($form, $files);
        } elseif ($form !== []) {
            $body = http_build_query($form);
            $type = 'application/x-www-form-urlencoded';
        }
        if (isset($type)) {
            if ($files !== []) {
                // Only this type names the boundary the body was written with.
                foreach (array_keys($headers) as $name) {
                    if (strtolower((string) $name) !== 'content-type') {
                        continue;
                    }

                    unset($headers[$name]);
                }
                $headers['Content-Type'] = $type;
            } elseif (!isset($names['content-type'])) {
                $headers['Content-Type'] = $type;
            }
        }
        if ($json !== null && !isset($names['accept'])) {
            $headers['Accept'] = 'application/json';
        }
        $request = new Request(strtoupper($method), $url, $headers, $body ?? '');
        if ($sink instanceof Pipe) {
            $sink->request = Exchange::describe($request);
        }
        return $this->send($request, $cancellation, new Receiving($maxBytes ?? $this->maxBytes, $decode, $sink));
    }

    public function stream(
        string $method,
        string $url,
        array $query = [],
        array $headers = [],
        mixed $json = null,
        array $form = [],
        array $files = [],
        string|StreamInterface|null $body = null,
        ?Cancellation $cancellation = null,
        ?int $maxBytes = null,
        bool $decode = true,
    ): ResponseContract {
        $loop = EventLoop::current();
        $pipe = new Pipe($loop);
        $request = Task::spawn($loop, function (Cancellation $own) use (
            $method,
            $url,
            $query,
            $headers,
            $json,
            $form,
            $files,
            $body,
            $cancellation,
            $maxBytes,
            $decode,
            $pipe,
        ): ResponseContract {
            // Given up when the caller gives it up, or when no one reads what it receives.
            $unsubscribe = $cancellation?->subscribe($own->cancel(...));
            try {
                return $this->request(
                    $method,
                    $url,
                    $query,
                    $headers,
                    $json,
                    $form,
                    $files,
                    $body,
                    $own,
                    $pipe,
                    $maxBytes,
                    $decode,
                );
            } finally {
                $unsubscribe?->__invoke();
            }
        });
        $request->onComplete(static function (Task $request) use ($pipe): void {
            try {
                $request->result();
                $pipe->finish();
            } catch (\Throwable $error) {
                $pipe->finish($error);
            }
        });
        $pipe->waitForHead();
        $opened = $pipe->opened();
        if ($opened === null) {
            // No success to stream: an error status, kept to be read as usual, or no answer at all.
            return $request->result();
        }
        [$status, $received, $decoding, $version] = $opened;
        if ($decoding) {
            unset($received['content-encoding'], $received['content-length']);
        }
        return new Response($status, $received, new PipeBody($pipe, $request), '', $version, $pipe->request);
    }

    public function get(string $url, array $query = [], array $headers = []): ResponseContract
    {
        return $this->request('GET', $url, $query, $headers);
    }

    public function post(
        string $url,
        mixed $json = null,
        array $form = [],
        array $files = [],
        array $headers = [],
    ): ResponseContract {
        return $this->request('POST', $url, [], $headers, $json, $form, $files);
    }

    public function put(
        string $url,
        mixed $json = null,
        array $form = [],
        array $files = [],
        array $headers = [],
    ): ResponseContract {
        return $this->request('PUT', $url, [], $headers, $json, $form, $files);
    }

    public function patch(
        string $url,
        mixed $json = null,
        array $form = [],
        array $files = [],
        array $headers = [],
    ): ResponseContract {
        return $this->request('PATCH', $url, [], $headers, $json, $form, $files);
    }

    public function delete(string $url, array $query = [], array $headers = []): ResponseContract
    {
        return $this->request('DELETE', $url, $query, $headers);
    }

    /**
     * Closes the kept connections; one in use is closed when its request
     * ends. Clients made from this one with timeout() or cookies() use
     * the same connections, and are closed with it.
     */
    public function close(): void
    {
        $this->transport->close();
    }

    /**
     * Sends a request, again when it is to be retried and may be.
     */
    private function send(RequestInterface $request, ?Cancellation $cancellation, Receiving $receiving): Response
    {
        $deadline = (hrtime(true) / 1_000_000_000) + $this->timeout;
        $body = $request->getBody();
        $resendable = $body->getSize() === 0 || $body->isSeekable();
        $safe = in_array($request->getMethod(), self::REPEATABLE, true);
        for ($tried = 0;; ++$tried) {
            $response = null;
            try {
                $redirected = false;
                $response = $this->once($request, $deadline, $cancellation, $receiving, $redirected);
                // An answer that redirected means the server acted on the request: what failed was
                // where it sent us next, and sending a POST again would do its work a second time.
                $again =
                    ($safe || !$redirected)
                    && in_array($response->getStatusCode(), $safe ? [429, 502, 503, 504] : [429, 503], true);
            } catch (NetworkException $error) {
                $again = $safe || $error->unsent && !$redirected;
            }
            // What went into a stream cannot be taken back, and the request is not made again into it.
            $wait =
                $again && $resendable && !$receiving->wrote && $tried < $this->retries
                    ? $this->wait($tried, $response)
                    : null;
            if ($wait === null || ((hrtime(true) / 1_000_000_000) + $wait) >= $deadline) {
                return $response ?? throw $error;
            }
            Async::sleep(EventLoop::current(), $wait, $cancellation);
            // The worker may have been busy after the pause ended; do not send a late retry.
            if ((hrtime(true) / 1_000_000_000) >= $deadline) {
                return $response ?? throw $error;
            }
        }
    }

    /**
     * How many seconds to wait before trying again, or null when the
     * server asks for longer than a request is allowed to take: the
     * answer is then the answer.
     */
    private function wait(int $tried, ?Response $response): ?float
    {
        // Doubled each time, and a little less by chance so that many callers do not come back at one moment.
        $wait = $this->retryDelay * (2 ** $tried) * (mt_rand(75, 100) / 100);
        $after = trim($response?->getHeaderLine('Retry-After') ?? '');
        if ($after === '') {
            return $wait;
        }
        // A number of seconds, or the time to come back at.
        if (preg_match('/^\d+$/D', $after) === 1) {
            $asked = (float) $after;
        } else {
            $now = $this->clock?->now() ?? new \DateTimeImmutable();
            $asked = (float) ((int) strtotime($after) - $now->getTimestamp());
        }
        if ($asked > $this->timeout) {
            return null;
        }
        return max($wait, $asked);
    }

    /**
     * Sends a request once and follows where its answer redirects to,
     * keeping and sending cookies on the way when there is a jar.
     *
     * @param bool $redirected Set once an answer has redirected, whatever comes after.
     */
    private function once(
        RequestInterface $request,
        float $deadline,
        ?Cancellation $cancellation,
        Receiving $receiving,
        bool &$redirected = false,
    ): Response {
        for ($followed = 0;; ++$followed) {
            $sending = $request;
            $cookies = $this->jar?->header($request->getUri()) ?? '';
            if ($cookies !== '') {
                $own = $request->getHeaderLine('Cookie');
                $sending = $request->withHeader('Cookie', $own === '' ? $cookies : "{$own}; {$cookies}");
            }
            $cancellation?->throwIfCancelled();
            if ((hrtime(true) / 1_000_000_000) >= $deadline) {
                throw new NetworkException(
                    Exchange::describe($sending)
                        . " failed: it took more than {$this->timeout} seconds, and was preparing to send when given up.",
                    $sending,
                    new TimedOut('preparing to send'),
                    unsent: true,
                );
            }
            $response = $this->transport->exchange($sending, $deadline, $this->timeout, $cancellation, $receiving);
            $this->jar?->store($request->getUri(), $response->getHeader('Set-Cookie'));
            $next = $this->redirected($request, $response);
            if ($next === null) {
                return $response;
            }
            if ($followed === $this->redirects) {
                throw new ClientException(
                    Exchange::describe($request) . " was redirected more than {$this->redirects} times.",
                );
            }
            $request = $next;
            $redirected = true;
        }
    }

    /**
     * The request to send next when the answer redirects, or null when it
     * is the answer to return.
     */
    private function redirected(RequestInterface $request, Response $response): ?RequestInterface
    {
        $status = $response->getStatusCode();
        $location = $response->getHeaderLine('Location');
        if ($this->redirects === 0 || $location === '' || !in_array($status, [301, 302, 303, 307, 308], true)) {
            return null;
        }
        $from = $request->getUri();
        $to = self::resolveLocation($from, $location);
        if ($to === null) {
            return null;
        }
        $method = $request->getMethod();
        $next = $request->withUri($to);
        if ($status === 303 && $method !== 'HEAD' || $status !== 307 && $status !== 308 && $method === 'POST') {
            // As browsers do: what was posted is not posted again, the result is fetched.
            $next = $next->withMethod('GET')->withBody(new Stream(''))->withoutHeader('Content-Type');
        } elseif ($request->getBody()->getSize() !== 0 && !$request->getBody()->isSeekable()) {
            // A body that can be read only once cannot be sent again.
            return null;
        }
        if (
            strtolower($from->getScheme() . '://' . $from->getAuthority()) !== strtolower(
                $to->getScheme() . '://' . $to->getAuthority(),
            )
        ) {
            // What proved who is asking was meant for the first server, not for wherever it points.
            $next = $next->withoutHeader('Authorization')->withoutHeader('Cookie');
        }
        return $next;
    }

    /**
     * A Location header's value as a whole URL, or null when it is not one
     * this client can follow.
     */
    private static function resolveLocation(UriInterface $from, string $location): ?UriInterface
    {
        $origin = $from->getScheme() . '://' . $from->getAuthority();
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $location) === 1) {
            $url = $location;
        } elseif (str_starts_with($location, '//')) {
            $url = $from->getScheme() . ':' . $location;
        } elseif (str_starts_with($location, '/')) {
            $url = $origin . $location;
        } elseif (str_starts_with($location, '?')) {
            $url = $origin . ($from->getPath() === '' ? '/' : $from->getPath()) . $location;
        } else {
            // Relative to the folder the request was in.
            $path = $from->getPath();
            $folder = substr($path, 0, (int) strrpos($path, '/') + 1);
            $url = $origin . ($folder === '' ? '/' : $folder) . $location;
        }
        try {
            $to = new Uri($url);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return in_array(strtolower($to->getScheme()), ['http', 'https'], true) && $to->getHost() !== '' ? $to : null;
    }
}
