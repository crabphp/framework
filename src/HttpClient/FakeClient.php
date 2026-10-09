<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Crab\Contracts\HttpClient\CookieJar as CookieJarContract;
use Crab\Engine\Async\Cancellation;
use Crab\Http\Stream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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
 *     $app->container->instance(Client::class, $http);
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
    ) {
        // Retries wait no time in a test.
        parent::__construct($baseUrl, $headers, redirects: $redirects, cookies: $cookies, retries: $retries, retryDelay: 0.0, transport: $this);
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
        return array_values(array_filter($this->requests, static fn (RequestInterface $request): bool => self::matches($pattern, $request)));
    }

    public function exchange(RequestInterface $request, float $deadline, float $timeout, ?Cancellation $cancellation): Response
    {
        $cancellation?->throwIfCancelled();
        $this->requests[] = $request;
        $what = Exchange::describe($request);
        foreach ($this->replies as $pattern => $reply) {
            if (!self::matches((string) $pattern, $request)) {
                continue;
            }
            if ($reply instanceof \Closure) {
                $reply = $reply($request);
            }
            if ($reply instanceof \Throwable) {
                throw $reply;
            }
            if (is_int($reply)) {
                $reply = self::response($reply);
            } elseif (!$reply instanceof ResponseInterface) {
                $reply = self::response(200, $reply);
            }
            // Made again so that it knows which request it answers, for throw() to name.
            return new Response($reply->getStatusCode(), $reply->getHeaders(), new Stream((string) $reply->getBody()), $reply->getReasonPhrase(), $reply->getProtocolVersion(), $what);
        }
        $known = $this->replies === [] ? 'It has none.' : 'It has: ' . implode(', ', array_keys($this->replies)) . '.';
        throw new \LogicException("Nothing fakes {$what}. Add a reply for it to the FakeClient. {$known}");
    }

    /**
     * There are no connections to close.
     */
    public function close(): void
    {
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
