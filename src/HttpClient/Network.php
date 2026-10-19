<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Pool;
use Crab\Engine\Async\PoolExhaustedException;
use Crab\Engine\EventLoop;
use Crab\Engine\Network\DnsResolver;
use Psr\Http\Message\RequestInterface;

/**
 * Carries requests over the network, on connections it keeps and uses
 * again, straight to the server or through a proxy.
 *
 * A server's address is found by its name without holding the worker;
 * see the engine's DnsResolver.
 *
 * @internal
 */
final class Network implements Transport
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
     * The kept connections to each server, by scheme, host and port.
     *
     * @var array<string, Pool>
     */
    private array $pools = [];
    /**
     * When an origin last finished work. HTTP/1.1 borrowing ends its idle
     * period; active HTTP/2 calls keep their session alive during a sweep.
     *
     * @var array<string, float>
     */
    private array $idleSince = [];
    /**
     * The next sweep of idle origins, on the connections' monotonic clock.
     */
    private float $pruneAt = 0.0;
    /**
     * The HTTP/2 connection to each server that speaks it, which carries every request to that server at once.
     *
     * @var array<string, Http2Session>
     */
    private array $sessions = [];
    /**
     * Calls using each HTTP/2 session, including callers waiting for capacity.
     *
     * @var array<int, int>
     */
    private array $http2Calls = [];
    /**
     * Requests waiting for a connection to each server, by what ends their wait.
     *
     * @var array<string, array<int, Cancellation>>
     */
    private array $waiting = [];
    /**
     * The proxy's host, port and, when it asks who is calling, the header that says so.
     *
     * @var array{string, int, ?string}|null
     */
    private readonly ?array $proxy;

    /**
     * @param array<string, mixed> $tls PHP's "ssl" context options for encrypted connections.
     * @param string|null $proxy "http://proxy.example:8080", with "user:password@" before the host when it asks.
     */
    public function __construct(
        private readonly float $connectTimeout = 5.0,
        private readonly int $connections = 8,
        private readonly array $tls = [],
        ?string $proxy = null,
        private readonly bool $http2 = true,
        private readonly DnsResolver $resolver = new DnsResolver(),
    ) {
        if ($proxy === null || $proxy === '') {
            $this->proxy = null;
            return;
        }
        $parts = parse_url($proxy);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'http' || !isset($parts['host'])) {
            throw new \LogicException(
                'A proxy is given as "http://host:port", with "user:password@" before the host when it asks. Proxies of other kinds, https:// and socks, are not supported.',
            );
        }
        $authorization = isset($parts['user'])
            ? 'Basic ' . base64_encode(rawurldecode($parts['user']) . ':' . rawurldecode($parts['pass'] ?? ''))
            : null;
        $this->proxy = [$parts['host'], $parts['port'] ?? 8080, $authorization];
    }

    public function exchange(
        RequestInterface $request,
        float $deadline,
        float $timeout,
        ?Cancellation $cancellation,
    ): Response {
        $what = Exchange::describe($request);
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        $host = $uri->getHost();
        if ($host === '' || $scheme !== 'http' && $scheme !== 'https') {
            throw new RequestException(
                "{$what} cannot be sent: a request needs an http:// or https:// URL with a host.",
                $request,
            );
        }
        if ($scheme === 'https' && !extension_loaded('openssl')) {
            throw new RequestException("{$what} cannot be sent: https needs PHP's openssl extension.", $request);
        }
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
        $origin = "{$scheme}://{$host}:{$port}";
        $this->pruneIdlePools();
        $body = $request->getBody();
        $resendable = $body->getSize() === 0 || $body->isSeekable();
        $repeatable = in_array($request->getMethod(), self::REPEATABLE, true) && $resendable;
        for ($attempt = 1;; ++$attempt) {
            // A closing HTTP/2 session may retire its pool before this call retries.
            $pool = $this->pool($scheme, $host, $port);
            try {
                $session = $this->sessions[$origin] ?? null;
                if ($session !== null && !$session->accepts()) {
                    $session = null;
                }
                try {
                    $slot = $session === null
                        ? $this->connection($pool, $deadline, $cancellation, $origin, $scheme, $host, $port)
                        : null;
                    $connection = $slot?->connection;
                    if ($slot === null && $session === null) {
                        // While this one waited, another request opened an HTTP/2 conversation that has room for it.
                        continue;
                    }
                    if ($connection !== null && $connection->protocol === 'h2') {
                        $session = $this->session($origin, $pool, $slot, $deadline);
                    }
                } catch (CancelledException $cancelled) {
                    throw $cancelled;
                } catch (PoolExhaustedException|TimedOut $error) {
                    $why =
                        $error instanceof TimedOut && $error->doing !== 'waiting for a connection'
                            ? "was {$error->doing} when given up"
                            : "every one of the {$this->connections} connections to {$host} was still busy";
                    throw new NetworkException(
                        "{$what} failed: it took more than {$timeout} seconds, and {$why}.",
                        $request,
                        $error,
                        unsent: true,
                    );
                } catch (\RuntimeException $error) {
                    throw new NetworkException(
                        "{$what} failed: {$error->getMessage()}.",
                        $request,
                        $error,
                        unsent: true,
                    );
                }
                if ($session !== null) {
                    $sent = false;
                    $sessionId = spl_object_id($session);
                    $this->http2Calls[$sessionId] = ($this->http2Calls[$sessionId] ?? 0) + 1;
                    try {
                        return $session->exchange($request, $deadline, $cancellation, $sent);
                    } catch (CancelledException $cancelled) {
                        throw $cancelled;
                    } catch (Unprocessed $error) {
                        // The server says it did not act on the request, so it may be made again, whatever it is.
                        if ($resendable && $attempt < 3) {
                            continue;
                        }
                        throw new NetworkException(
                            "{$what} failed: {$error->getMessage()}.",
                            $request,
                            $error,
                            unsent: true,
                        );
                    } catch (\RuntimeException $error) {
                        $why = $error instanceof TimedOut
                            ? "it took more than {$timeout} seconds, and was {$error->doing} when given up"
                            : $error->getMessage();
                        throw new NetworkException(
                            "{$what} failed: {$why}.",
                            $request,
                            $error,
                            unsent: $error instanceof TimedOut && !$sent,
                        );
                    } finally {
                        if (--$this->http2Calls[$sessionId] === 0) {
                            unset($this->http2Calls[$sessionId]);
                        }
                        if ($sent && ($this->sessions[$origin] ?? null) === $session) {
                            $this->idleSince[$origin] = hrtime(true) / 1_000_000_000;
                        }
                    }
                }
                $exchange = new Exchange($connection, $deadline, $cancellation);
                try {
                    $response = $exchange->run($request);
                } catch (\Throwable $error) {
                    $pool->discard($slot);
                    if ($error instanceof CancelledException || !$error instanceof \RuntimeException) {
                        throw $error;
                    }
                    // A kept connection the server had closed carried nothing: the request is sent once more, on a new one.
                    if (
                        $connection->used
                        && !$exchange->answered
                        && !$error instanceof TimedOut
                        && $repeatable
                        && $attempt === 1
                    ) {
                        continue;
                    }
                    $why = $error instanceof TimedOut
                        ? "it took more than {$timeout} seconds, and was {$error->doing} when given up"
                        : $error->getMessage();
                    throw new NetworkException(
                        "{$what} failed: {$why}.",
                        $request,
                        $error,
                        unsent: $error instanceof TimedOut && !$exchange->sent,
                    );
                }
                if ($exchange->reusable) {
                    $connection->used = true;
                    $connection->idleSince = hrtime(true) / 1_000_000_000;
                    $pool->release($slot);
                } else {
                    $pool->discard($slot);
                }
                return $response;
            } finally {
                $this->forgetEmptyPool($origin, $pool);
            }
        }
    }

    /**
     * Closes the kept connections; one in use is closed when its request ends.
     */
    public function close(): void
    {
        $sessions = $this->sessions;
        $this->sessions = [];
        foreach ($sessions as $session) {
            $session->close();
        }
        $pools = $this->pools;
        $this->pools = [];
        $this->idleSince = [];
        $this->pruneAt = 0.0;
        foreach ($pools as $pool) {
            $pool->close();
        }
    }

    /**
     * The HTTP/2 conversation on a connection just made, or the one
     * another request made to the same server a moment earlier, which is
     * then the one to use.
     */
    private function session(string $origin, Pool $pool, PooledConnection $slot, float $deadline): Http2Session
    {
        $connection = $slot->connection;
        $existing = $this->sessions[$origin] ?? null;
        if ($existing !== null && $existing->accepts()) {
            $pool->discard($slot);
            return $existing;
        }
        $session = new Http2Session($connection, EventLoop::current(), function () use (
            $origin,
            $pool,
            $slot,
            &$session,
        ): void {
            if (($this->sessions[$origin] ?? null) === $session) {
                unset($this->sessions[$origin]);
            }
            // The connection stays lent to its session for as long as the session lasts.
            $pool->discard($slot);
            $this->forgetEmptyPool($origin, $pool);
        });
        $this->sessions[$origin] = $session;
        try {
            $session->start($deadline);
            $this->idleSince[$origin] = hrtime(true) / 1_000_000_000;
            // Whoever waits for a connection to this server need wait no longer.
            foreach ($this->waiting[$origin] ?? [] as $waiter) {
                $waiter->cancel();
            }
        } catch (\Throwable $error) {
            $session->close($error);
            throw $error instanceof TimedOut
                ? $error
                : new \RuntimeException('the connection was lost while sending', previous: $error);
        }
        return $session;
    }

    /**
     * A connection to use: a kept one that still works, else a new one.
     * Waiting for one to come free ends at the request's deadline. It
     * ends too, with null, when an HTTP/2 conversation is opened to the
     * same server meanwhile: that carries every request at once, and the
     * connection it took will not come free.
     */
    private function connection(
        Pool $pool,
        float $deadline,
        ?Cancellation $cancellation,
        string $origin,
        string $scheme,
        string $host,
        int $port,
    ): ?PooledConnection {
        $left = $deadline - (hrtime(true) / 1_000_000_000);
        if ($left <= 0) {
            throw new TimedOut('waiting for a connection');
        }
        // The pool waits until cancelled, so the deadline is made a cancellation of its own.
        $limit = new Cancellation();
        $loop = EventLoop::current();
        $timer = $loop->delay($left, static fn() => $limit->cancel());
        $unsubscribe = $cancellation?->subscribe(static fn() => $limit->cancel());
        $this->waiting[$origin][spl_object_id($limit)] = $limit;
        try {
            while (true) {
                /** @var PooledConnection $slot */
                $slot = $pool->acquire($limit);
                unset($this->idleSince[$origin]);
                $connection = $slot->connection;
                // A handed-over slot may resume after its caller's deadline.
                if ((hrtime(true) / 1_000_000_000) >= $deadline || $cancellation?->isCancelled() === true) {
                    $connection?->used ? $pool->discard($slot) : $pool->release($slot);
                    $cancellation?->throwIfCancelled();
                    throw new TimedOut('waiting for a connection');
                }
                if ($connection === null) {
                    try {
                        // Open in the borrowing caller, including after a failed connection freed this slot.
                        $slot->connection =
                            $connection = $this->connect($scheme, $host, $port, $deadline, $cancellation);
                    } catch (\Throwable $error) {
                        $pool->discard($slot);
                        throw $error;
                    }
                    if ((hrtime(true) / 1_000_000_000) >= $deadline || $cancellation?->isCancelled() === true) {
                        $pool->discard($slot);
                        $cancellation?->throwIfCancelled();
                        throw new TimedOut('connecting');
                    }
                }
                if (!$connection->used) {
                    return $slot;
                }
                if (
                    ((hrtime(true) / 1_000_000_000) - $connection->idleSince) < self::IDLE
                    && $connection->isReusable()
                ) {
                    return $slot;
                }
                $pool->discard($slot);
            }
        } catch (CancelledException $cancelled) {
            $cancellation?->throwIfCancelled();
            if ((hrtime(true) / 1_000_000_000) < $deadline && ($this->sessions[$origin] ?? null)?->accepts() === true) {
                return null;
            }
            throw new TimedOut('waiting for a connection');
        } finally {
            unset($this->waiting[$origin][spl_object_id($limit)]);
            if (($this->waiting[$origin] ?? []) === []) {
                unset($this->waiting[$origin]);
            }
            $loop->cancel($timer);
            $unsubscribe?->__invoke();
        }
    }

    /**
     * Remove an empty origin, or remember when its whole pool became idle.
     * Never change a replacement pool or another caller's live resources.
     */
    private function forgetEmptyPool(string $origin, Pool $pool): void
    {
        if (
            ($this->pools[$origin] ?? null) !== $pool
            || isset($this->sessions[$origin])
            || isset($this->waiting[$origin])
        ) {
            return;
        }
        $stats = $pool->stats();
        if ($stats['borrowed'] !== 0 || $stats['waiting'] !== 0) {
            return;
        }
        if ($stats['idle'] === 0) {
            unset($this->pools[$origin], $this->idleSince[$origin]);
        } else {
            // An attempt that never borrowed must not renew this idle period.
            $this->idleSince[$origin] ??= hrtime(true) / 1_000_000_000;
        }
    }

    /**
     * Reclaim expired pools even when their origins are never visited again.
     * Only origins without active calls or waiting callers may be retired.
     */
    private function pruneIdlePools(): void
    {
        $now = hrtime(true) / 1_000_000_000;
        if ($now < $this->pruneAt) {
            return;
        }
        $this->pruneAt = $now + self::IDLE;
        foreach ($this->idleSince as $origin => $since) {
            if (($now - $since) < self::IDLE) {
                continue;
            }
            $session = $this->sessions[$origin] ?? null;
            if ($session !== null) {
                if (isset($this->http2Calls[spl_object_id($session)]) || isset($this->waiting[$origin])) {
                    continue;
                }
                unset($this->idleSince[$origin]);
                $session->close();
                continue;
            }
            $pool = $this->pools[$origin] ?? null;
            unset($this->idleSince[$origin]);
            if ($pool === null || isset($this->waiting[$origin])) {
                continue;
            }
            $stats = $pool->stats();
            if ($stats['borrowed'] !== 0 || $stats['waiting'] !== 0) {
                continue;
            }
            unset($this->pools[$origin]);
            $pool->close();
        }
    }

    private function pool(string $scheme, string $host, int $port): Pool
    {
        return $this->pools["{$scheme}://{$host}:{$port}"] ??= new Pool(
            // Asked for now and not when the client was made: a worker has a loop of its own.
            EventLoop::current(),
            // Reserve capacity first; opening a socket then uses the borrowing caller's deadline.
            static fn(): PooledConnection => new PooledConnection(),
            $this->connections,
            // Each caller brings its own deadline; this is only the longest anyone could wait.
            86_400.0,
            static fn(PooledConnection $slot) => $slot->connection?->close(),
        );
    }

    private function connect(
        string $scheme,
        string $host,
        int $port,
        float $requestDeadline,
        ?Cancellation $cancellation,
    ): Connection {
        $deadline = min($requestDeadline, (hrtime(true) / 1_000_000_000) + $this->connectTimeout);
        $tlsHost = $scheme === 'https' ? $host : null;
        // HTTP/2 is offered first to a server that may speak it; which one is spoken is settled as encryption is.
        $tls = ['alpn_protocols' => $this->http2 ? 'h2,http/1.1' : 'http/1.1', ...$this->tls];
        $loop = EventLoop::current();
        // Through a proxy, the connection is made to the proxy.
        [$toHost, $toPort] = $this->proxy ?? [$host, $port];
        $left = $deadline - (hrtime(true) / 1_000_000_000);
        if ($left <= 0) {
            throw new TimedOut('connecting');
        }
        $limit = new Cancellation();
        $timer = $loop->delay($left, static fn() => $limit->cancel());
        $unsubscribe = $cancellation?->subscribe(static fn() => $limit->cancel());
        try {
            try {
                $addresses = $this->resolver->resolve($loop, $toHost, $limit);
            } catch (CancelledException $error) {
                $cancellation?->throwIfCancelled();
                if ($deadline === $requestDeadline) {
                    throw new TimedOut('finding the server address');
                }
                throw new \RuntimeException(
                    "connecting to {$toHost} took more than {$this->connectTimeout} seconds",
                    previous: $error,
                );
            }
        } finally {
            $loop->cancel($timer);
            $unsubscribe?->__invoke();
        }
        $failure = null;
        foreach ($addresses as $address) {
            try {
                $cancellation?->throwIfCancelled();
                if ((hrtime(true) / 1_000_000_000) >= $deadline) {
                    throw new TimedOut('connecting');
                }
                if ($this->proxy === null) {
                    return Connection::open($loop, $address, $port, $tlsHost, $tls, $deadline, $cancellation);
                }
                $connection = Connection::open(
                    $loop,
                    $address,
                    $toPort,
                    $tlsHost,
                    $tls,
                    $deadline,
                    $cancellation,
                    encrypt: false,
                );
                if ($tlsHost === null) {
                    // What is not encrypted is handed to the proxy to pass on.
                    $connection->forwarded = true;
                    $connection->proxyAuthorization = $this->proxy[2];
                    return $connection;
                }
                try {
                    $this->tunnel($connection, $host, $port, $deadline, $cancellation);
                    $connection->encrypt($deadline, $cancellation);
                } catch (\Throwable $error) {
                    $connection->close();
                    throw $error;
                }
                return $connection;
            } catch (TimedOut $error) {
                if ($deadline === $requestDeadline) {
                    throw $error;
                }
                $failure ??= new \RuntimeException(
                    "connecting to {$toHost} took more than {$this->connectTimeout} seconds",
                    previous: $error,
                );
                break;
            } catch (CancelledException $error) {
                throw $error;
            } catch (\RuntimeException $error) {
                // The first address's failure is the one told: a later address is often one the server does not listen on.
                $failure ??= new \RuntimeException(
                    "{$toHost} could not be reached: {$error->getMessage()}",
                    previous: $error,
                );
            }
        }
        throw $failure;
    }

    /**
     * Asks the proxy for a connection straight through to a server, over
     * which the encrypted exchange then runs unseen by the proxy.
     */
    private function tunnel(
        Connection $connection,
        string $host,
        int $port,
        float $deadline,
        ?Cancellation $cancellation,
    ): void {
        $ask = "CONNECT {$host}:{$port} HTTP/1.1\r\nHost: {$host}:{$port}\r\n";
        if ($this->proxy[2] !== null) {
            $ask .= "Proxy-Authorization: {$this->proxy[2]}\r\n";
        }
        $cancellation?->throwIfCancelled();
        if ((hrtime(true) / 1_000_000_000) >= $deadline) {
            throw new TimedOut('connecting through the proxy');
        }
        $connection->write($ask . "\r\n", $deadline, $cancellation);
        $answer = '';
        while (!str_contains($answer, "\r\n\r\n")) {
            $bytes = $connection->read($deadline, $cancellation);
            if ($bytes === null || strlen($answer) > 16_384) {
                throw new \RuntimeException('the proxy closed the connection without answering');
            }
            $answer .= $bytes;
        }
        if (preg_match('~^HTTP/1\.[01] 2\d\d~', $answer) !== 1) {
            throw new \RuntimeException(
                'the proxy would not connect to '
                . $host
                . ': it answered "'
                . trim((string) strtok($answer, "\r\n"))
                . '"',
            );
        }
    }
}

/**
 * A place in the connection pool. Its socket is opened only after it is
 * borrowed, so a replacement uses the waiting caller's own deadline.
 *
 * @internal
 */
final class PooledConnection
{
    /**
     * The socket kept for this place, once a caller has opened it.
     */
    public ?Connection $connection = null;
}
