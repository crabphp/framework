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
     * The HTTP/2 connection to each server that speaks it, which carries every request to that server at once.
     *
     * @var array<string, Http2Session>
     */
    private array $sessions = [];
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
            throw new \LogicException('A proxy is given as "http://host:port", with "user:password@" before the host when it asks. Proxies of other kinds, https:// and socks, are not supported.');
        }
        $authorization = isset($parts['user'])
            ? 'Basic ' . base64_encode(rawurldecode($parts['user']) . ':' . rawurldecode($parts['pass'] ?? ''))
            : null;
        $this->proxy = [$parts['host'], $parts['port'] ?? 8080, $authorization];
    }

    public function exchange(RequestInterface $request, float $deadline, float $timeout, ?Cancellation $cancellation): Response
    {
        $what = Exchange::describe($request);
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        $host = $uri->getHost();
        if ($host === '' || ($scheme !== 'http' && $scheme !== 'https')) {
            throw new RequestException("{$what} cannot be sent: a request needs an http:// or https:// URL with a host.", $request);
        }
        if ($scheme === 'https' && !extension_loaded('openssl')) {
            throw new RequestException("{$what} cannot be sent: https needs PHP's openssl extension.", $request);
        }
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
        $origin = "{$scheme}://{$host}:{$port}";
        $pool = $this->pool($scheme, $host, $port);
        $body = $request->getBody();
        $resendable = $body->getSize() === 0 || $body->isSeekable();
        $repeatable = in_array($request->getMethod(), self::REPEATABLE, true) && $resendable;
        for ($attempt = 1; ; ++$attempt) {
            $session = $this->sessions[$origin] ?? null;
            if ($session !== null && !$session->accepts()) {
                $session = null;
            }
            try {
                $connection = $session === null ? $this->connection($pool, $deadline, $cancellation, $origin) : null;
                if ($connection === null && $session === null) {
                    // While this one waited, another request opened an HTTP/2 conversation that has room for it.
                    continue;
                }
                if ($connection !== null && $connection->protocol === 'h2') {
                    $session = $this->session($origin, $pool, $connection, $deadline);
                }
            } catch (CancelledException $cancelled) {
                throw $cancelled;
            } catch (PoolExhaustedException | TimedOut $error) {
                throw new NetworkException("{$what} failed: it took more than {$timeout} seconds, and every one of the {$this->connections} connections to {$host} was still busy.", $request, $error, unsent: true);
            } catch (\RuntimeException $error) {
                throw new NetworkException("{$what} failed: {$error->getMessage()}.", $request, $error, unsent: true);
            }
            if ($session !== null) {
                try {
                    return $session->exchange($request, $deadline, $cancellation);
                } catch (CancelledException $cancelled) {
                    throw $cancelled;
                } catch (Unprocessed $error) {
                    // The server says it did not act on the request, so it may be made again, whatever it is.
                    if ($resendable && $attempt < 3) {
                        continue;
                    }
                    throw new NetworkException("{$what} failed: {$error->getMessage()}.", $request, $error, unsent: true);
                } catch (\RuntimeException $error) {
                    $why = $error instanceof TimedOut ? "it took more than {$timeout} seconds, and was {$error->doing} when given up" : $error->getMessage();
                    throw new NetworkException("{$what} failed: {$why}.", $request, $error);
                }
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
                throw new NetworkException("{$what} failed: {$why}.", $request, $error);
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
        foreach ($pools as $pool) {
            $pool->close();
        }
    }

    /**
     * The HTTP/2 conversation on a connection just made, or the one
     * another request made to the same server a moment earlier, which is
     * then the one to use.
     */
    private function session(string $origin, Pool $pool, Connection $connection, float $deadline): Http2Session
    {
        $existing = $this->sessions[$origin] ?? null;
        if ($existing !== null && $existing->accepts()) {
            $pool->discard($connection);
            return $existing;
        }
        $session = new Http2Session($connection, EventLoop::current(), function () use ($origin, $pool, $connection, &$session): void {
            if (($this->sessions[$origin] ?? null) === $session) {
                unset($this->sessions[$origin]);
            }
            // The connection stays lent to its session for as long as the session lasts.
            $pool->discard($connection);
        });
        $this->sessions[$origin] = $session;
        try {
            $session->start($deadline);
            // Whoever waits for a connection to this server need wait no longer.
            foreach ($this->waiting[$origin] ?? [] as $waiter) {
                $waiter->cancel();
            }
        } catch (\Throwable $error) {
            $session->close($error);
            throw $error instanceof TimedOut ? $error : new \RuntimeException('the connection was lost while sending', previous: $error);
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
    private function connection(Pool $pool, float $deadline, ?Cancellation $cancellation, string $origin): ?Connection
    {
        $left = $deadline - hrtime(true) / 1_000_000_000;
        if ($left <= 0) {
            throw new TimedOut('waiting for a connection');
        }
        // The pool waits until cancelled, so the deadline is made a cancellation of its own.
        $limit = new Cancellation();
        $loop = EventLoop::current();
        $timer = $loop->delay($left, static fn () => $limit->cancel());
        $unsubscribe = $cancellation?->subscribe(static fn () => $limit->cancel());
        $this->waiting[$origin][spl_object_id($limit)] = $limit;
        try {
            while (true) {
                /** @var Connection $connection */
                $connection = $pool->acquire($limit);
                // Making a connection takes its own time; the request's may have run out meanwhile.
                if (hrtime(true) / 1_000_000_000 >= $deadline || $cancellation?->isCancelled() === true) {
                    $connection->used ? $pool->discard($connection) : $pool->release($connection);
                    $cancellation?->throwIfCancelled();
                    throw new TimedOut('waiting for a connection');
                }
                if (!$connection->used) {
                    return $connection;
                }
                if (hrtime(true) / 1_000_000_000 - $connection->idleSince < self::IDLE && $connection->isReusable()) {
                    return $connection;
                }
                $pool->discard($connection);
            }
        } catch (CancelledException $cancelled) {
            $cancellation?->throwIfCancelled();
            if (hrtime(true) / 1_000_000_000 < $deadline && ($this->sessions[$origin] ?? null)?->accepts() === true) {
                return null;
            }
            throw new TimedOut('waiting for a connection');
        } finally {
            unset($this->waiting[$origin][spl_object_id($limit)]);
            $loop->cancel($timer);
            $unsubscribe?->__invoke();
        }
    }

    private function pool(string $scheme, string $host, int $port): Pool
    {
        return $this->pools["{$scheme}://{$host}:{$port}"] ??= new Pool(
            // Asked for now and not when the client was made: a worker has a loop of its own.
            EventLoop::current(),
            fn (): Connection => $this->connect($scheme, $host, $port),
            $this->connections,
            // Each caller brings its own deadline; this is only the longest anyone could wait.
            86400.0,
            static fn (Connection $connection) => $connection->close(),
        );
    }

    private function connect(string $scheme, string $host, int $port): Connection
    {
        $deadline = hrtime(true) / 1_000_000_000 + $this->connectTimeout;
        $tlsHost = $scheme === 'https' ? $host : null;
        // HTTP/2 is offered first to a server that may speak it; which one is spoken is settled as encryption is.
        $tls = ['alpn_protocols' => $this->http2 ? 'h2,http/1.1' : 'http/1.1', ...$this->tls];
        $loop = EventLoop::current();
        // Through a proxy, the connection is made to the proxy.
        [$toHost, $toPort] = $this->proxy ?? [$host, $port];
        $failure = null;
        foreach ($this->resolver->resolve($loop, $toHost) as $address) {
            try {
                if ($this->proxy === null) {
                    return Connection::open($loop, $address, $port, $tlsHost, $tls, $deadline);
                }
                $connection = Connection::open($loop, $address, $toPort, $tlsHost, $tls, $deadline, encrypt: false);
                if ($tlsHost === null) {
                    // What is not encrypted is handed to the proxy to pass on.
                    $connection->forwarded = true;
                    $connection->proxyAuthorization = $this->proxy[2];
                    return $connection;
                }
                try {
                    $this->tunnel($connection, $host, $port, $deadline);
                    $connection->encrypt($deadline);
                } catch (\Throwable $error) {
                    $connection->close();
                    throw $error;
                }
                return $connection;
            } catch (TimedOut $error) {
                $failure ??= new \RuntimeException("connecting to {$toHost} took more than {$this->connectTimeout} seconds", previous: $error);
            } catch (\RuntimeException $error) {
                // The first address's failure is the one told: a later address is often one the server does not listen on.
                $failure ??= new \RuntimeException("{$toHost} could not be reached: {$error->getMessage()}", previous: $error);
            }
        }
        throw $failure;
    }

    /**
     * Asks the proxy for a connection straight through to a server, over
     * which the encrypted exchange then runs unseen by the proxy.
     */
    private function tunnel(Connection $connection, string $host, int $port, float $deadline): void
    {
        $ask = "CONNECT {$host}:{$port} HTTP/1.1\r\nHost: {$host}:{$port}\r\n";
        if ($this->proxy[2] !== null) {
            $ask .= "Proxy-Authorization: {$this->proxy[2]}\r\n";
        }
        $connection->write($ask . "\r\n", $deadline);
        $answer = '';
        while (!str_contains($answer, "\r\n\r\n")) {
            $bytes = $connection->read($deadline);
            if ($bytes === null || strlen($answer) > 16384) {
                throw new \RuntimeException('the proxy closed the connection without answering');
            }
            $answer .= $bytes;
        }
        if (preg_match('~^HTTP/1\.[01] 2\d\d~', $answer) !== 1) {
            throw new \RuntimeException('the proxy would not connect to ' . $host . ': it answered "' . trim((string) strtok($answer, "\r\n")) . '"');
        }
    }
}
