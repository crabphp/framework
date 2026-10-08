<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Crab\Contracts\HttpClient\Client as ClientContract;
use Crab\Contracts\HttpClient\Response as ResponseContract;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Pool;
use Crab\Engine\Async\PoolExhaustedException;
use Crab\Engine\EventLoop;
use Crab\Http\Request;
use Crab\Http\Stream;
use Crab\Http\Uri;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * An HTTP/1.1 client that does not hold the worker up: while a request
 * waits for its answer, the worker serves other requests.
 *
 *     $http = new Client();
 *     $user = $http->get('https://api.example.com/users/7')->json();
 *
 *     $stripe = new Client('https://api.stripe.com/v1', headers: ['Authorization' => "Bearer {$key}"]);
 *     $charge = $stripe->post('/charges', json: ['amount' => 500]);
 *
 * Connections are kept and used again, up to a number per server; a
 * request beyond that waits for one. Redirects are followed, and a
 * compressed answer is handed over as what it says.
 *
 * Finding a server's address by its name is the one step that holds the
 * worker, for as long as the system's resolver takes; an address found is
 * remembered for a minute.
 */
final class Client implements ClientContract
{
    /**
     * Methods that may be sent again when a kept connection turns out to
     * have been closed, because doing them twice is doing them once.
     */
    private const REPEATABLE = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'];
    /**
     * How long a kept connection is trusted without being used, in seconds.
     */
    private const IDLE = 30.0;
    /**
     * How long an address found for a name is remembered, in seconds.
     */
    private const REMEMBER = 60.0;

    /**
     * The kept connections to each server, by scheme, host and port.
     *
     * @var array<string, Pool>
     */
    private array $pools = [];
    /**
     * Addresses found for names, and until when each is remembered.
     *
     * @var array<string, array{list<string>, float}>
     */
    private array $addresses = [];

    /**
     * @param string $baseUrl What a path given to a request is added to: "https://api.example.com/v1".
     * @param array<string, string|list<string>> $headers Sent with every request, unless the request says otherwise.
     * @param float $timeout Seconds a whole exchange may take, redirects included.
     * @param float $connectTimeout Seconds connecting to a server may take.
     * @param int $redirects How many redirects are followed for one request; 0 to return the redirect itself.
     * @param int $connections How many requests may be on their way to one server at once.
     * @param array<string, mixed> $tls PHP's "ssl" context options for encrypted connections, such as ['cafile' => '/path/ca.pem']. The server's certificate is verified unless this says not to.
     */
    public function __construct(
        private readonly string $baseUrl = '',
        private readonly array $headers = [],
        private readonly float $timeout = 30.0,
        private readonly float $connectTimeout = 5.0,
        private readonly int $redirects = 5,
        private readonly int $connections = 8,
        private readonly array $tls = [],
    ) {
        if ($timeout <= 0 || $connectTimeout <= 0 || $redirects < 0 || $connections < 1) {
            throw new \LogicException('An HTTP client needs timeouts above zero, at least one connection per server and a number of redirects that is not negative.');
        }
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->send($request, $this->timeout, null);
    }

    public function request(
        string $method,
        string $url,
        array $query = [],
        array $headers = [],
        mixed $json = null,
        array $form = [],
        string|StreamInterface|null $body = null,
        ?float $timeout = null,
        ?Cancellation $cancellation = null,
    ): ResponseContract {
        if (($json !== null) + ($form !== []) + ($body !== null) > 1) {
            throw new \LogicException('A request has one body: give json:, form: or body:, not several.');
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $url) !== 1) {
            if ($this->baseUrl === '') {
                throw new \LogicException("\"{$url}\" is not a whole URL, and this client has no base URL to add it to. Give \"https://...\", or make the client with a base URL.");
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
        } elseif ($form !== []) {
            $body = http_build_query($form);
            $type = 'application/x-www-form-urlencoded';
        }
        if (isset($type) && !isset($names['content-type'])) {
            $headers['Content-Type'] = $type;
        }
        if ($json !== null && !isset($names['accept'])) {
            $headers['Accept'] = 'application/json';
        }
        return $this->send(new Request(strtoupper($method), $url, $headers, $body ?? ''), $timeout ?? $this->timeout, $cancellation);
    }

    public function get(string $url, array $query = [], array $headers = []): ResponseContract
    {
        return $this->request('GET', $url, $query, $headers);
    }

    public function post(string $url, mixed $json = null, array $headers = []): ResponseContract
    {
        return $this->request('POST', $url, [], $headers, $json);
    }

    public function put(string $url, mixed $json = null, array $headers = []): ResponseContract
    {
        return $this->request('PUT', $url, [], $headers, $json);
    }

    public function patch(string $url, mixed $json = null, array $headers = []): ResponseContract
    {
        return $this->request('PATCH', $url, [], $headers, $json);
    }

    public function delete(string $url, array $query = [], array $headers = []): ResponseContract
    {
        return $this->request('DELETE', $url, $query, $headers);
    }

    /**
     * Closes the kept connections; one in use is closed when its request ends.
     */
    public function close(): void
    {
        $pools = $this->pools;
        $this->pools = [];
        foreach ($pools as $pool) {
            $pool->close();
        }
    }

    /**
     * Sends a request and follows where its answer redirects to.
     */
    private function send(RequestInterface $request, float $timeout, ?Cancellation $cancellation): Response
    {
        $deadline = hrtime(true) / 1_000_000_000 + $timeout;
        for ($followed = 0; ; ++$followed) {
            $response = $this->exchange($request, $deadline, $timeout, $cancellation);
            $next = $this->redirected($request, $response);
            if ($next === null) {
                return $response;
            }
            if ($followed === $this->redirects) {
                throw new ClientException(self::describe($request) . " was redirected more than {$this->redirects} times.");
            }
            $request = $next;
        }
    }

    /**
     * One request and its answer, on a kept connection when there is one.
     */
    private function exchange(RequestInterface $request, float $deadline, float $timeout, ?Cancellation $cancellation): Response
    {
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        $host = $uri->getHost();
        if ($host === '' || ($scheme !== 'http' && $scheme !== 'https')) {
            throw new RequestException(self::describe($request) . ' cannot be sent: a request needs an http:// or https:// URL with a host.', $request);
        }
        if ($scheme === 'https' && !extension_loaded('openssl')) {
            throw new RequestException(self::describe($request) . ' cannot be sent: https needs PHP\'s openssl extension.', $request);
        }
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
        $pool = $this->pool($scheme, $host, $port);
        $body = $request->getBody();
        $repeatable = in_array($request->getMethod(), self::REPEATABLE, true) && ($body->getSize() === 0 || $body->isSeekable());
        for ($attempt = 1; ; ++$attempt) {
            try {
                $connection = $this->connection($pool, $cancellation);
            } catch (CancelledException $cancelled) {
                throw $cancelled;
            } catch (PoolExhaustedException $error) {
                throw new NetworkException(self::describe($request) . " failed: every one of the {$this->connections} connections to {$host} stayed busy.", $request, $error);
            } catch (\RuntimeException $error) {
                throw new NetworkException(self::describe($request) . " failed: {$error->getMessage()}.", $request, $error);
            }
            $exchange = new Exchange($connection, $deadline, $cancellation);
            try {
                $response = $exchange->run($request);
            } catch (\Throwable $error) {
                $pool->discard($connection);
                if ($error instanceof CancelledException || !$error instanceof \RuntimeException) {
                    throw $error;
                }
                // A kept connection the server had closed carried nothing: the request is sent once more, on a new one.
                if ($connection->used && !$exchange->answered && !$error instanceof TimedOut && $repeatable && $attempt === 1) {
                    continue;
                }
                $why = $error instanceof TimedOut ? "it took more than {$timeout} seconds, and was {$error->doing} when given up" : $error->getMessage();
                throw new NetworkException(self::describe($request) . " failed: {$why}.", $request, $error);
            }
            if ($exchange->reusable) {
                $connection->used = true;
                $connection->idleSince = hrtime(true) / 1_000_000_000;
                $pool->release($connection);
            } else {
                $pool->discard($connection);
            }
            return $response;
        }
    }

    /**
     * A connection to use: a kept one that still works, else a new one.
     */
    private function connection(Pool $pool, ?Cancellation $cancellation): Connection
    {
        while (true) {
            /** @var Connection $connection */
            $connection = $pool->acquire($cancellation);
            if (!$connection->used) {
                return $connection;
            }
            if (hrtime(true) / 1_000_000_000 - $connection->idleSince < self::IDLE && $connection->isReusable()) {
                return $connection;
            }
            $pool->discard($connection);
        }
    }

    private function pool(string $scheme, string $host, int $port): Pool
    {
        return $this->pools["{$scheme}://{$host}:{$port}"] ??= new Pool(
            // Asked for now and not when the client was made: a worker has a loop of its own.
            EventLoop::current(),
            fn (): Connection => $this->connect($scheme, $host, $port),
            $this->connections,
            $this->timeout,
            static fn (Connection $connection) => $connection->close(),
        );
    }

    private function connect(string $scheme, string $host, int $port): Connection
    {
        $failure = null;
        foreach ($this->resolve($host) as $address) {
            try {
                return Connection::open(
                    EventLoop::current(),
                    $address,
                    $port,
                    $scheme === 'https' ? $host : null,
                    $this->tls,
                    hrtime(true) / 1_000_000_000 + $this->connectTimeout,
                );
            } catch (TimedOut $error) {
                $failure = new \RuntimeException("connecting to {$host} took more than {$this->connectTimeout} seconds", previous: $error);
            } catch (\RuntimeException $error) {
                $failure = new \RuntimeException("{$host} could not be reached: {$error->getMessage()}", previous: $error);
            }
        }
        throw $failure;
    }

    /**
     * The numeric addresses of a host. Looking a name up holds the worker
     * while the system answers, so what is found is remembered a while.
     *
     * @return non-empty-list<string>
     */
    private function resolve(string $host): array
    {
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }
        $now = hrtime(true) / 1_000_000_000;
        if (isset($this->addresses[$host]) && $this->addresses[$host][1] > $now) {
            return $this->addresses[$host][0];
        }
        $found = @gethostbynamel($host);
        if ($found === false || $found === []) {
            unset($this->addresses[$host]);
            throw new \RuntimeException("no address was found for the name {$host}");
        }
        $this->addresses[$host] = [$found, $now + self::REMEMBER];
        return $found;
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
        if (($status === 303 && $method !== 'HEAD') || ($status !== 307 && $status !== 308 && $method === 'POST')) {
            // As browsers do: what was posted is not posted again, the result is fetched.
            $next = $next->withMethod('GET')->withBody(new Stream(''))->withoutHeader('Content-Type');
        } elseif ($request->getBody()->getSize() !== 0 && !$request->getBody()->isSeekable()) {
            // A body that can be read only once cannot be sent again.
            return null;
        }
        if (strtolower($from->getScheme() . '://' . $from->getAuthority()) !== strtolower($to->getScheme() . '://' . $to->getAuthority())) {
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

    /**
     * A request by its method and where it goes, without the query string
     * or anything else that may hold a secret.
     */
    private static function describe(RequestInterface $request): string
    {
        $uri = $request->getUri();
        $where = $uri->getHost() === '' ? $uri->getPath() : $uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() === null ? '' : ':' . $uri->getPort()) . $uri->getPath();
        return $request->getMethod() . ' ' . $where;
    }
}
