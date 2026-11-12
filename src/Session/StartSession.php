<?php

declare(strict_types=1);

namespace Crab\Session;

use Crab\Contracts\Http\Handler;
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Http\Request;
use Crab\Contracts\Http\UsesContainer;
use Crab\Contracts\Session\Driver;
use Crab\Contracts\Session\Record;
use Crab\Contracts\Session\Session as SessionContract;
use Crab\Http\Cookie;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Middleware that gives each request its session, on the request's "session"
 * attribute, where an action takes it as `Session $session`.
 *
 * The session is read when it is first used, and written after the action
 * only when the request changed it, saw a flash, needs a new id, or is past
 * half its lifetime, which it then starts again: a session lasts for as long
 * as its browser keeps using it. A request that never uses its session costs
 * no reading and no writing.
 *
 * When a session is written, a lottery may also sweep the driver's expired
 * sessions: on `$lottery[0]` requests in `$lottery[1]`. [0, 1] never does.
 *
 *     ->middleware(StartSession::class)
 *     ->middleware(new StartSession(lifetime: 1_800))
 */
final readonly class StartSession implements Middleware, UsesContainer
{
    /**
     * @param Driver|null $driver Where sessions are kept; the bound one, with SessionPlugin, when given on a route.
     * @param int|null $lifetime Seconds a session lasts without being used; 7,200 when none is given or bound.
     * @param string|null $name The cookie's name. Without one, "__Host-session" when the cookie is secure,
     *     which a browser keeps only for this host, path / and HTTPS; "session" over plain HTTP.
     * @param bool|null $secure Whether the cookie is sent only over HTTPS; yes when none is given or bound.
     * @param array{int, int}|null $lottery The chances of sweeping expired sessions, [2, 100] when none is given.
     */
    public function __construct(
        private ?Driver $driver = null,
        private ?int $lifetime = null,
        private ?string $name = null,
        private ?bool $secure = null,
        private ?array $lottery = null,
        private ?ClockInterface $clock = null,
        private ?LoggerInterface $logger = null,
    ) {
        if ($lifetime !== null && $lifetime < 1) {
            throw new \InvalidArgumentException("A session lasts at least one second; {$lifetime} was given.");
        }
        if ($name !== null && preg_match('/^[A-Za-z0-9_.-]+$/D', $name) !== 1) {
            throw new \InvalidArgumentException(
                "\"{$name}\" cannot be a cookie's name: use letters, digits, _, . and -.",
            );
        }
        if ($lottery !== null) {
            [$chances, $outOf] = array_values($lottery) + [null, null];
            if (
                count($lottery) !== 2
                || !is_int($chances)
                || !is_int($outOf)
                || $outOf < 1
                || $chances < 0
                || $chances > $outOf
            ) {
                throw new \InvalidArgumentException(
                    'A session lottery is two whole numbers, the chances and what they are out of, such as [2, 100]; [0, 1] never sweeps.',
                );
            }
        }
    }

    public function withContainer(ContainerInterface $container): static
    {
        if ($this->driver !== null) {
            return $this;
        }
        if (!$container->has(self::class)) {
            throw new \LogicException('StartSession needs a place to keep sessions: list SessionPlugin in app.php.');
        }
        $bound = $container->get(self::class);
        return new self(
            $bound->driver,
            $this->lifetime ?? $bound->lifetime,
            $this->name ?? $bound->name,
            $this->secure ?? $bound->secure,
            $this->lottery ?? $bound->lottery,
            $this->clock ?? $bound->clock,
            $this->logger ?? $bound->logger,
        );
    }

    public function handle(Request $request, Handler $next): ResponseInterface
    {
        $driver = $this->driver ?? throw new \LogicException(
            'StartSession needs a place to keep sessions: list SessionPlugin in app.php.',
        );
        $name = $this->cookieName();
        $cookie = $request->getCookieParams()[$name] ?? null;
        $cookie = is_string($cookie) && $cookie !== '' ? $cookie : null;
        $now = ($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp();
        $found = false;
        $session = new Session(static function () use ($driver, $cookie, $now, &$found): ?Record {
            $record = $cookie === null ? null : $driver->read($cookie, $now);
            $found = $record !== null;
            return $record;
        });

        $response = $next->handle($request->withAttribute(SessionContract::ATTRIBUTE, $session));

        $lifetime = $this->lifetime ?? 7_200;
        if (!$session->started() || !$session->needsWriting($now, $lifetime)) {
            return $response;
        }
        // Only an id this driver gave out is kept: one the browser made up is never adopted.
        $current = $found ? $cookie : null;
        $latest = $current === null ? null : $driver->read($current, $now);
        $record = $session->record($latest, $now + $lifetime);
        if ($current !== null && ($session->newId() || $record === null)) {
            $driver->destroy($current);
            $current = null;
        }
        if ($record === null) {
            $response = $cookie === null
                ? $response
                : $response->withAddedHeader('Set-Cookie', (string) $this->cookie('', 0));
        } else {
            $value = $driver->write($current, $record);
            $response = $response->withAddedHeader('Set-Cookie', (string) $this->cookie($value, $lifetime));
        }
        $this->sweep($driver, $now);
        return $response;
    }

    private function sweep(Driver $driver, int $now): void
    {
        [$chances, $outOf] = $this->lottery ?? [2, 100];
        if ($chances === 0 || random_int(1, $outOf) > $chances) {
            return;
        }
        try {
            $driver->sweep($now);
        } catch (\Throwable $error) {
            // Housekeeping does not fail the request it runs after; it is logged, and thrown only
            // where there is no logger, since nothing may fail unseen.
            if ($this->logger === null) {
                throw $error;
            }
            $this->logger->warning('Sweeping expired sessions failed: {message}', [
                'message' => $error->getMessage(),
                'exception' => $error,
            ]);
        }
    }

    private function cookie(string $value, int $maxAge): Cookie
    {
        return new Cookie(
            $this->cookieName(),
            $value,
            $maxAge,
            secure: $this->secure ?? true,
            httpOnly: true,
            sameSite: 'Lax',
        );
    }

    private function cookieName(): string
    {
        return $this->name ?? ($this->secure ?? true ? '__Host-session' : 'session');
    }
}
