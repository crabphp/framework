<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Crab\Contracts\HttpClient\CookieJar as CookieJarContract;
use Crab\Engine\Async\Cancellation;
use Crab\Http\Stream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The HTTP client for tests: nothing leaves the process, each request is
 * answered with a reply written in advance, and every request is kept
 * to be looked at afterwards.
 *
 *     $http = new FakeClient([
 *         'GET https://api.example.com/users/7' => ['id' => 7, 'name' => 'Ada'],
 *         'POST https://api.example.com/charges' => FakeClient::response(402, ['error' => 'card_declined']),
 *         'https://slow.example.com/*' => new \RuntimeException('it timed out'),
 *     ]);
 *     $app->container->instance(\Crab\Contracts\HttpClient\Client::class, $http);
 *
 *     // ... run the code under test ...
 *
 *     self::assertCount(1, $http->sent('POST https://api.example.com/charges'));
 *
 * A reply is found by the request's method and URL, the first that
 * matches; "*" stands for anything, and the method may be left out. The
 * query string counts only when the pattern has one. A request no reply
 * matches throws, naming it, so that a test never waits on the network.
 *
 * A reply is one of:
 *
 *     an array or an object     sent as JSON, with 200
 *     a string                  sent as text, with 200
 *     an int                    that status, with no body
 *     a response                as it is; FakeClient::response() makes one
 *     an exception              thrown, as when a server cannot be reached
 *     a closure                 called with the request; returns any of these
 *
 * Everything else is the real client: the base URL, headers, redirects,
 * cookies and throw() behave as they do over the network.
 */
final class FakeClient extends Client implements Transport
{
    /**
     * Every request that was sent, in order.
     *
     * @var list<RequestInterface>
     */
    public array $requests = [];

    /**
     * The original fake carries every copy's requests and owns their shared history.
     */
    private readonly self $recorder;

    /**
     * @param array<string, mixed> $replies By pattern: "GET https://api.example.com/users/*", or without the method.
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        private readonly array $replies = [],
        string $baseUrl = '',
        array $headers = [],
        int $redirects = 5,
        ?CookieJarContract $cookies = null,
        int $retries = 0,
        int $maxBytes = self::MAX_BYTES,
    ) {
        $this->recorder = $this;
        // Retries wait no time in a test.
        parent::__construct(
            $baseUrl,
            $headers,
            redirects: $redirects,
            cookies: $cookies,
            retries: $retries,
            retryDelay: 0.0,
            transport: $this,
            maxBytes: $maxBytes,
        );
    }

    public function __clone(): void
    {
        $this->requests = &$this->recorder->requests;
    }

    /**
     * As the real client's, but without the wait between tries: a test has nobody to be patient with.
     */
    public function retry(int $times, float $delay = 0.2): static
    {
        return parent::retry($times, 0.0);
    }

    /**
     * A response to reply with.
     *
     * @param mixed $body An array or an object is sent as JSON, a string as it is.
     * @param array<string, string|list<string>> $headers
     */
    public static function response(int $status = 200, mixed $body = null, array $headers = []): Response
    {
        $given = array_change_key_case($headers, CASE_LOWER);
        if (is_array($body) || is_object($body)) {
            $body = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $type = 'application/json';
        } elseif ($body !== null) {
            $body = (string) $body;
            $type = 'text/plain; charset=utf-8';
        }
        if (isset($type) && !isset($given['content-type'])) {
            $headers['Content-Type'] = $type;
        }
        $lists = [];
        foreach ($headers as $name => $value) {
            $lists[(string) $name] = (array) $value;
        }
        return new Response($status, $lists, $body ?? '');
    }

    /**
     * The requests that were sent and match a pattern, written as a
     * reply's is; every request when none is given.
     *
     * @return list<RequestInterface>
     */
    public function sent(string $pattern = '*'): array
    {
        return array_values(array_filter($this->requests, static fn(RequestInterface $request): bool => self::matches(
            $pattern,
            $request,
        )));
    }

    public function exchange(
        RequestInterface $request,
        float $deadline,
        float $timeout,
        ?Cancellation $cancellation,
        Receiving $receiving,
    ): Response {
        $cancellation?->throwIfCancelled();
        $record = $request->withBody(self::copyBody($request->getBody()));
        $this->requests[] = $record;
        $what = Exchange::describe($request);
        foreach ($this->replies as $pattern => $reply) {
            if (!self::matches((string) $pattern, $request)) {
                continue;
            }
            if ($reply instanceof \Closure) {
                // A reply may consume or close its stream without rewriting the sent history.
                $body = self::copyBody($record->getBody());
                $record->getBody()->rewind();
                $reply = $reply($request->withBody($body));
            }
            if ($reply instanceof \Throwable) {
                throw $reply;
            }
            if (is_int($reply)) {
                $reply = self::response($reply);
            } elseif (!$reply instanceof ResponseInterface) {
                $reply = self::response(200, $reply);
            }
            return self::receive($reply, $request, $receiving);
        }
        $patterns = array_map(static fn(string $pattern): string => substr(
            $pattern,
            0,
            strcspn($pattern, '?#'),
        ), array_keys($this->replies));
        $known = $patterns === [] ? 'It has none.' : 'It has: ' . implode(', ', $patterns) . '.';
        throw new \LogicException("Nothing fakes {$what}. Add a reply for it to the FakeClient. {$known}");
    }

    /**
     * The reply as the network would hand it over: its body received as
     * the call says, counted, decompressed and sent where the call said,
     * and knowing which request it answers, for throw() to name.
     */
    private static function receive(ResponseInterface $reply, RequestInterface $request, Receiving $receiving): Response
    {
        $status = $reply->getStatusCode();
        $length = $reply->getHeaderLine('Content-Length');
        $bodiless = $request->getMethod() === 'HEAD' || $status === 204 || $status === 304 || $status < 200;
        $body = $reply->getBody();
        try {
            if (!$bodiless && preg_match('/^\d{1,18}$/D', $length) === 1) {
                $receiving->expect((int) $length);
            }
            $sink = $receiving->sink($status, $reply->getHeaderLine('Content-Encoding'), $bodiless);
            if (!$bodiless) {
                if ($body->isSeekable()) {
                    $body->rewind();
                }
                while (!$body->eof()) {
                    $sink->write($body->read(65_536));
                }
            }
            if ($sink->decoding) {
                $reply = $reply->withoutHeader('Content-Encoding')->withoutHeader('Content-Length');
            }
            return new Response(
                $status,
                $reply->getHeaders(),
                $sink->body(),
                $reply->getReasonPhrase(),
                $reply->getProtocolVersion(),
                Exchange::describe($request),
            );
        } catch (TooLarge $tooLarge) {
            throw $tooLarge->named(Exchange::describe($request));
        }
    }

    /**
     * There are no connections to close.
     */
    public function close(): void {}

    /**
     * As on the wire, a seekable body starts at zero and any other body at its current position.
     * Copy in chunks into a temporary stream so large bodies spill to disk.
     */
    private static function copyBody(StreamInterface $body): Stream
    {
        $copy = new Stream();
        if ($body->getSize() === 0) {
            return $copy;
        }
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            $bytes = $body->read(65_536);
            if ($copy->write($bytes) !== strlen($bytes)) {
                throw new \RuntimeException('Cannot keep the fake request body. Check temporary storage space.');
            }
        }
        $copy->rewind();
        return $copy;
    }

    private static function matches(string $pattern, RequestInterface $request): bool
    {
        $uri = $request->getUri();
        $url = $uri->getScheme() . '://' . $uri->getAuthority() . ($uri->getPath() === '' ? '/' : $uri->getPath());
        if (str_contains($pattern, '?') && $uri->getQuery() !== '') {
            $url .= '?' . $uri->getQuery();
        }
        if (preg_match('/^[A-Z]+ /', $pattern) === 1) {
            $url = $request->getMethod() . ' ' . $url;
        }
        $expression = '~^' . str_replace('\*', '.*', preg_quote($pattern, '~')) . '$~';
        return preg_match($expression, $url) === 1;
    }
}
