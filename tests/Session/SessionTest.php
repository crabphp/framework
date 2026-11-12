<?php

declare(strict_types=1);

namespace Crab\Tests\Session;

use Crab\Cache\ArrayStore;
use Crab\Cache\FileStore;
use Crab\Contracts\Http\Handler;
use Crab\Contracts\Http\Request as RequestContract;
use Crab\Contracts\Session\Driver;
use Crab\Contracts\Session\Record;
use Crab\Contracts\Session\Session as SessionContract;
use Crab\Http\Request;
use Crab\Http\Response;
use Crab\Session\CookieDriver;
use Crab\Session\Session;
use Crab\Session\StartSession;
use Crab\Session\StoreDriver;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;

/**
 * A driver that counts what it is asked, over a real store.
 */
final class CountingDriver implements Driver
{
    public int $reads = 0;
    public int $writes = 0;
    public int $sweeps = 0;
    public bool $sweepFails = false;

    /**
     * @var list<string>
     */
    public array $destroyed = [];

    public function __construct(
        public readonly Driver $inner = new StoreDriver(new ArrayStore()),
    ) {}

    public function read(string $cookie, int $now): ?Record
    {
        $this->reads++;
        return $this->inner->read($cookie, $now);
    }

    public function write(?string $cookie, Record $record): string
    {
        $this->writes++;
        return $this->inner->write($cookie, $record);
    }

    public function destroy(string $cookie): void
    {
        $this->destroyed[] = $cookie;
        $this->inner->destroy($cookie);
    }

    public function sweep(int $now): int
    {
        $this->sweeps++;
        if ($this->sweepFails) {
            throw new \RuntimeException('The disk is gone.');
        }
        return $this->inner->sweep($now);
    }
}

/**
 * Runs an action given as a closure of the request's session.
 */
final readonly class WithSession implements Handler
{
    /**
     * @param \Closure(SessionContract): string $action
     */
    public function __construct(
        private \Closure $action,
    ) {}

    public function handle(RequestContract $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionContract::ATTRIBUTE);
        if (!$session instanceof SessionContract) {
            throw new \LogicException('No session on the request.');
        }
        return Response::text(($this->action)($session));
    }
}

final class SessionTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('@' . self::NOW));
    }

    public function testASessionKeepsValuesAndIsReadOnlyWhenUsed(): void
    {
        $loads = 0;
        $session = new Session(static function () use (&$loads): Record {
            $loads++;
            return new Record(['cart' => [1, 2]], [], self::NOW + 100);
        });
        self::assertFalse($session->started());
        self::assertSame(0, $loads, 'Nothing is read until the session is used.');

        self::assertSame([1, 2], $session->get('cart'));
        self::assertSame('none', $session->get('missing', 'none'));
        $session->put('step', 2);
        self::assertTrue($session->has('step'));
        self::assertSame(2, $session->pull('step'));
        self::assertFalse($session->has('step'));
        self::assertSame(['cart' => [1, 2]], $session->all());
        self::assertSame(1, $loads);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function notJson(): iterable
    {
        yield 'object' => [new \stdClass(), 'keeps JSON values, and "value" was given stdClass'];
        yield 'object in a list' => [[1, new \ArrayObject()], 'was given ArrayObject'];
        yield 'infinity' => [INF, 'is not a finite number'];
        yield 'bytes' => ["\xff\xfe", 'is not UTF-8 text'];
        yield 'closure' => [static fn() => 1, 'was given Closure'];
    }

    #[DataProvider('notJson')]
    public function testASessionKeepsOnlyJsonValues(mixed $value, string $message): void
    {
        $session = new Session(static fn(): ?Record => null);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $session->put('value', $value);
    }

    public function testAFlashLastsForTheNextRequestThatUsesTheSession(): void
    {
        $driver = new CountingDriver();
        $middleware = $this->middleware($driver);

        $cookie = $this->cookie($this->through($middleware, null, static function (SessionContract $s): string {
            $s->flash('status', 'Saved.');
            return '';
        }));
        self::assertSame('Saved.', $this->body(
            $middleware,
            $cookie,
            static fn(SessionContract $s): string => (string) $s->get('status'),
        ));
        self::assertSame('gone', $this->body(
            $middleware,
            $cookie,
            static fn(SessionContract $s): string => (string) $s->get('status', 'gone'),
        ));
    }

    public function testAFlashPutAgainIsKeptLikeAnyValue(): void
    {
        $middleware = $this->middleware(new CountingDriver());
        $cookie = $this->cookie($this->through($middleware, null, static function (SessionContract $s): string {
            $s->flash('draft', 'a');
            $s->put('name', 'Ada');
            return '';
        }));
        $this->through($middleware, $cookie, static function (SessionContract $s): string {
            $s->put('draft', 'b');
            return '';
        });
        self::assertSame('b Ada', $this->body(
            $middleware,
            $cookie,
            static fn(SessionContract $s): string => $s->get('draft') . ' ' . $s->get('name'),
        ));
    }

    public function testARequestThatNeverUsesItsSessionReadsAndWritesNothing(): void
    {
        $driver = new CountingDriver();
        $middleware = $this->middleware($driver);
        $response = $this->through(
            $middleware,
            'f' . str_repeat('0', 63),
            static fn(SessionContract $s): string => 'ok',
        );
        self::assertSame([0, 0, ''], [$driver->reads, $driver->writes, $response->getHeaderLine('Set-Cookie')]);

        $this->through($middleware, null, static fn(SessionContract $s): string => (string) $s->get('x'));
        self::assertSame(0, $driver->writes, 'Reading a new, empty session writes nothing.');
    }

    public function testTheCookieCarriesTheIdWithItsAttributes(): void
    {
        $response = $this->through(
            $this->middleware(new CountingDriver()),
            null,
            static function (SessionContract $s): string {
                $s->put('a', 1);
                return '';
            },
        );
        self::assertMatchesRegularExpression(
            '/^__Host-session=[0-9a-f]{64}; Max-Age=7200; Path=\/; Secure; HttpOnly; SameSite=Lax$/',
            $response->getHeaderLine('Set-Cookie'),
        );
        $plain = new StartSession(new CountingDriver(), secure: false, lottery: [0, 1], clock: $this->clock);
        self::assertStringStartsWith(
            'session=',
            $this->through(
                $plain,
                null,
                static function (SessionContract $s): string {
                    $s->put('a', 1);
                    return '';
                },
                'session',
            )->getHeaderLine('Set-Cookie'),
        );
    }

    public function testAnIdTheServerDidNotGiveIsNeverAdopted(): void
    {
        $driver = new CountingDriver();
        $chosen = str_repeat('a', 64);
        $response = $this->through($this->middleware($driver), $chosen, static function (SessionContract $s): string {
            $s->put('user', 7);
            return '';
        });
        $id = $this->cookie($response);
        self::assertNotSame($chosen, $id, 'A fixed id an attacker planted is replaced.');
        self::assertNull($driver->inner->read($chosen, self::NOW));
    }

    public function testRegenerateKeepsTheValuesUnderANewIdAndForgetsTheOld(): void
    {
        $driver = new CountingDriver();
        $middleware = $this->middleware($driver);
        $old = $this->cookie($this->through($middleware, null, static function (SessionContract $s): string {
            $s->put('cart', [3]);
            return '';
        }));
        $new = $this->cookie($this->through($middleware, $old, static function (SessionContract $s): string {
            $s->regenerate();
            return '';
        }));
        self::assertNotSame($old, $new);
        self::assertSame([$old], $driver->destroyed);
        self::assertNull($driver->inner->read($old, self::NOW));
        self::assertSame('[3]', $this->body($middleware, $new, static fn(SessionContract $s): string => json_encode(
            $s->get('cart'),
            JSON_THROW_ON_ERROR,
        )));
    }

    public function testInvalidateForgetsTheSessionAndItsCookie(): void
    {
        $driver = new CountingDriver();
        $middleware = $this->middleware($driver);
        $cookie = $this->cookie($this->through($middleware, null, static function (SessionContract $s): string {
            $s->put('user', 7);
            return '';
        }));
        $response = $this->through($middleware, $cookie, static function (SessionContract $s): string {
            $s->invalidate();
            return '';
        });
        self::assertStringStartsWith('__Host-session=; Max-Age=0;', $response->getHeaderLine('Set-Cookie'));
        self::assertNull($driver->inner->read($cookie, self::NOW));
    }

    public function testTwoRequestsAtOnceEachKeepWhatTheyChanged(): void
    {
        $driver = new CountingDriver();
        $middleware = $this->middleware($driver);
        $cookie = $this->cookie($this->through($middleware, null, static function (SessionContract $s): string {
            $s->put('a', 1);
            $s->put('b', 1);
            return '';
        }));
        // The first request reads, the second runs whole while the first is still in its action.
        $this->through($middleware, $cookie, function (SessionContract $s) use ($middleware, $cookie): string {
            $s->get('a');
            $this->through($middleware, $cookie, static function (SessionContract $other): string {
                $other->put('b', 2);
                $other->put('c', 2);
                return '';
            });
            $s->put('a', 3);
            $s->forget('c');
            return '';
        });
        self::assertSame('{"a":3,"b":2}', $this->body(
            $middleware,
            $cookie,
            static fn(SessionContract $s): string => json_encode($s->all(), JSON_THROW_ON_ERROR),
        ));
    }

    public function testASessionLastsWhileItIsUsedAndEndsWhenItIsNot(): void
    {
        $driver = new CountingDriver();
        $middleware = new StartSession($driver, lifetime: 100, lottery: [0, 1], clock: $this->clock);
        $cookie = $this->cookie($this->through($middleware, null, static function (SessionContract $s): string {
            $s->put('a', 1);
            return '';
        }));
        $writes = $driver->writes;

        $this->clock->advance('+40 seconds');
        $this->body($middleware, $cookie, static fn(SessionContract $s): string => (string) $s->get('a'));
        self::assertSame($writes, $driver->writes, 'Within half its lifetime, reading writes nothing.');

        $this->clock->advance('+20 seconds');
        $this->body($middleware, $cookie, static fn(SessionContract $s): string => (string) $s->get('a'));
        self::assertSame($writes + 1, $driver->writes, 'Past half its lifetime, it is kept for another lifetime.');

        $this->clock->advance('+99 seconds');
        self::assertSame('1', $this->body(
            $middleware,
            $cookie,
            static fn(SessionContract $s): string => (string) $s->get('a'),
        ));
        $this->clock->advance('+101 seconds');
        self::assertSame('none', $this->body(
            $middleware,
            $cookie,
            static fn(SessionContract $s): string => (string) $s->get('a', 'none'),
        ));
    }

    public function testTheLotterySweepsAndAFailedSweepIsLogged(): void
    {
        $driver = new CountingDriver();
        $put = static function (SessionContract $s): string {
            $s->put('a', 1);
            return '';
        };
        $this->through(new StartSession($driver, lottery: [0, 1], clock: $this->clock), null, $put);
        self::assertSame(0, $driver->sweeps);
        $this->through(new StartSession($driver, lottery: [1, 1], clock: $this->clock), null, $put);
        self::assertSame(1, $driver->sweeps);

        $driver->sweepFails = true;
        $logger = new class extends AbstractLogger {
            /**
             * @var list<string>
             */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] =
                    $level . ' ' . str_replace('{message}', (string) ($context['message'] ?? ''), (string) $message);
            }
        };
        $response = $this->through(
            new StartSession($driver, lottery: [1, 1], clock: $this->clock, logger: $logger),
            null,
            $put,
        );
        self::assertSame(200, $response->getStatusCode(), 'A failed sweep does not fail the request.');
        self::assertSame(['warning Sweeping expired sessions failed: The disk is gone.'], $logger->lines);
    }

    /**
     * @return iterable<string, array{array<int, mixed>}>
     */
    public static function badLotteries(): iterable
    {
        yield 'one number' => [[2]];
        yield 'more chances than draws' => [[3, 2]];
        yield 'out of nothing' => [[0, 0]];
        yield 'negative' => [[-1, 10]];
        yield 'text' => [['2', '100']];
    }

    /**
     * @param array<int, mixed> $lottery
     */
    #[DataProvider('badLotteries')]
    public function testALotteryIsTwoWholeNumbers(array $lottery): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A session lottery is two whole numbers');
        /** @phpstan-ignore argument.type */
        new StartSession(new CountingDriver(), lottery: $lottery);
    }

    public function testWithoutADriverTheMiddlewareSaysWhatIsMissing(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('list SessionPlugin in app.php');
        new StartSession()->handle(new Request('GET', 'http://shop.test/'), new WithSession(static fn(): string => ''));
    }

    public function testTheCookieDriverKeepsTheSessionSignedInItsCookie(): void
    {
        $driver = new CookieDriver(str_repeat('k', 32));
        $middleware = $this->middleware($driver);
        $cookie = $this->cookie($this->through($middleware, null, static function (SessionContract $s): string {
            $s->put('theme', 'dark');
            return '';
        }));
        self::assertSame('dark', $this->body(
            $middleware,
            $cookie,
            static fn(SessionContract $s): string => (string) $s->get('theme'),
        ));

        [$payload, $signature] = explode('.', $cookie);
        $changed = rtrim(
            strtr(
                base64_encode(str_replace('dark', 'evil', (string) base64_decode(strtr($payload, '-_', '+/'), true))),
                '+/',
                '-_',
            ),
            '=',
        );
        self::assertNull($driver->read("{$changed}.{$signature}", self::NOW), 'A changed cookie is refused.');
        self::assertNull(new CookieDriver(str_repeat('z', 32))->read($cookie, self::NOW), 'Another key refuses it.');
        self::assertNull($driver->read('not-a-session', self::NOW));
        self::assertNull($driver->read($cookie, self::NOW + 7_201), 'The expiry inside the cookie holds.');
    }

    public function testTheCookieDriverRefusesAShortKeyAndTooMuchData(): void
    {
        try {
            new CookieDriver('short');
            self::fail('A short key was taken.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('at least 32 bytes', $error->getMessage());
        }
        $this->expectException(\LengthException::class);
        $this->expectExceptionMessage('set session.driver to file or redis');
        new CookieDriver(str_repeat('k', 32))->write(
            null,
            new Record(['big' => str_repeat('x', 4_000)], [], self::NOW + 10),
        );
    }

    public function testTheStoreDriverKeepsRecordsUnderARandomIdAndSweepsExpiredOnes(): void
    {
        $directory = sys_get_temp_dir() . '/crab-sessions-' . bin2hex(random_bytes(6));
        try {
            foreach ([new ArrayStore(), new FileStore($directory)] as $store) {
                $driver = new StoreDriver($store);
                $id = $driver->write(null, new Record(['a' => 1], ['a'], self::NOW + 10));
                self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $id);
                self::assertSame($id, $driver->write($id, new Record(['a' => 2], [], self::NOW + 10)));
                self::assertEquals(new Record(['a' => 2], [], self::NOW + 10), $driver->read($id, self::NOW));
                self::assertNull($driver->read('../../etc/passwd', self::NOW), 'Only an id is looked up.');
                $driver->write(null, new Record(['old' => true], [], self::NOW + 1));
                self::assertSame(1, $driver->sweep(self::NOW + 5));
                self::assertNotNull($driver->read($id, self::NOW + 5));
                $driver->destroy($id);
                self::assertNull($driver->read($id, self::NOW));
            }
        } finally {
            foreach (glob($directory . '/*/*') ?: [] as $file) {
                unlink($file);
            }
            foreach (glob($directory . '/*') ?: [] as $folder) {
                is_dir($folder) ? rmdir($folder) : unlink($folder);
            }
            @rmdir($directory);
        }
    }

    public function testARecordReadsBackAsItWasKept(): void
    {
        $record = new Record(
            ['n' => 1.0, 'list' => [1, 'a'], 'map' => ['x' => null], '7' => true],
            ['n'],
            self::NOW + 1,
        );
        self::assertEquals($record, Record::fromJson($record->toJson(), self::NOW));
        self::assertSame(1.0, Record::fromJson($record->toJson(), self::NOW)?->values['n']);
        self::assertNull(Record::fromJson($record->toJson(), self::NOW + 1), 'An expired record is none.');
        self::assertNull(Record::fromJson('{"values":[]}', self::NOW));
        self::assertNull(Record::fromJson('not json', self::NOW));
    }

    private function middleware(Driver $driver): StartSession
    {
        return new StartSession($driver, lottery: [0, 1], clock: $this->clock);
    }

    /**
     * @param \Closure(SessionContract): string $action
     */
    private function through(
        StartSession $middleware,
        ?string $cookie,
        \Closure $action,
        string $name = '__Host-session',
    ): ResponseInterface {
        $headers = $cookie === null ? [] : ['Cookie' => "theme=x; {$name}={$cookie}"];
        return $middleware->handle(new Request('GET', 'https://shop.test/', $headers), new WithSession($action));
    }

    /**
     * @param \Closure(SessionContract): string $action
     */
    private function body(StartSession $middleware, string $cookie, \Closure $action): string
    {
        return (string) $this->through($middleware, $cookie, $action)->getBody();
    }

    private function cookie(ResponseInterface $response): string
    {
        self::assertSame(
            1,
            preg_match('/^[^=]+=([^;]+);/', $response->getHeaderLine('Set-Cookie'), $match),
            'A cookie is set.',
        );
        return rawurldecode($match[1]);
    }
}
