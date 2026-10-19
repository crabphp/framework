<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\RevoltEventLoop;
use Crab\Engine\Network\DnsResolver;
use Crab\Engine\Tests\Support\TestCase;
use RuntimeException;

final class DnsResolverTest extends TestCase
{
    /**
     * @var list<resource>
     */
    private array $sockets = [];
    /**
     * @var list<string>
     */
    private array $watchers = [];
    private ?EventLoop $loop = null;
    private string $hosts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hosts = sys_get_temp_dir() . '/crab-hosts-' . bin2hex(random_bytes(6));
        file_put_contents(
            $this->hosts,
            "# comment\n127.0.0.1 localhost box.test  # trailing\n::1 localhost\nnot-an-address broken\n10.0.0.7 Mixed.Case\n",
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->watchers as $watcher) {
            $this->loop?->cancel($watcher);
        }
        foreach ($this->sockets as $socket) {
            @fclose($socket);
        }
        @unlink($this->hosts);
        parent::tearDown();
    }

    public function testNamesAreFoundWithoutHoldingTheLoopAndKeptAsLongAsTheyHold(): void
    {
        $loop = $this->loop = $this->newLoop();
        $asked = [];
        $answer = null;
        $server = $this->nameServer(
            $loop,
            $asked,
            [
                'api.example.test' => [
                    [1, 60, "\x0A\x00\x00\x01"],
                    [1, 30, "\x0A\x00\x00\x02"],
                ],
                // Another name the name stands for comes first in the answer, and is passed over.
                'www.example.test' => [
                    [5, 60, "\x03cdn\x07example\x04test\x00"],
                    [1, 60, "\xC0\xA8\x01\x09"],
                ],
                'six.example.test' => [[28, 60, inet_pton('2001:db8::5')]],
            ],
            hold: static function (\Closure $send) use (&$answer): void {
                if ($answer === null) {
                    $answer = $send;
                } else {
                    $send();
                }
            },
        );
        $dns = new DnsResolver([$server], 10.0, 1, false, $this->hosts);
        $found = $this->within(
            $loop,
            function () use ($loop, $dns, &$answer): array {
                $lookup = Task::spawn($loop, static fn(#[\SensitiveParameter] Cancellation $token): array => [
                    $dns->resolve($loop, 'api.example.test', $token),
                    $dns->resolve($loop, 'API.Example.Test.', $token),
                    $dns->resolve($loop, 'www.example.test', $token),
                    $dns->resolve($loop, 'six.example.test', $token),
                    $dns->resolve($loop, '192.0.2.4', $token),
                    $dns->resolve($loop, '[2001:db8::1]', $token),
                    $dns->resolve($loop, 'localhost', $token),
                    $dns->resolve($loop, 'mixed.case', $token),
                ]);
                $this->deferCleanup($lookup->cancel(...));
                while ($answer === null) {
                    Async::sleep($loop, 0);
                }
                self::assertFalse($lookup->isComplete(), 'Other loop work runs while the first DNS answer is held.');
                $answer();
                return $lookup->await();
            },
            'The held DNS question and subsequent lookups did not finish within five seconds.',
        );

        self::assertSame(
            [
                ['10.0.0.1', '10.0.0.2'],
                ['10.0.0.1', '10.0.0.2'],
                ['192.168.1.9'],
                ['2001:db8::5'],
                ['192.0.2.4'],
                ['2001:db8::1'],
                ['127.0.0.1', '::1'],
                ['10.0.0.7'],
            ],
            $found,
        );
        self::assertSame(
            ['api.example.test A', 'www.example.test A', 'six.example.test A', 'six.example.test AAAA'],
            $asked,
            'A name kept, a numeric address and a name in the hosts file are not asked for.',
        );

        $dns->forget();
        $this->within($loop, static fn(): array => $dns->resolve($loop, 'api.example.test'));
        self::assertCount(5, $asked, 'Forgotten, it is asked for again.');
    }

    public function testANameThatIsNotThereAndAServerThatIsSilent(): void
    {
        $loop = $this->loop = $this->newLoop();
        $asked = [];
        $server = $this->nameServer($loop, $asked, [
            'known.test' => [[1, 60, "\x0A\x00\x00\x03"]],
            'cut.test' => 'truncated',
            'failing.test' => 'failure',
        ]);
        $silent = stream_socket_server('udp://127.0.0.1:0', $code, $reason, STREAM_SERVER_BIND);
        $this->sockets[] = $silent;
        $silentAddress = (string) stream_socket_get_name($silent, false);

        $dns = new DnsResolver([$silentAddress, $server], 0.05, 1, false, $this->hosts);
        $started = hrtime(true);
        self::assertSame(
            ['10.0.0.3'],
            $this->within($loop, static fn(): array => $dns->resolve($loop, 'known.test')),
            'A server that says nothing is given up on and the next is asked.',
        );
        self::assertGreaterThan(0.04, (hrtime(true) - $started) / 1e9);

        $only = new DnsResolver([$server], 10.0, 1, false, $this->hosts);
        foreach ([
            'missing.test',
            'cut.test',
            'failing.test',
            'not a name',
            '-bad-.test',
            str_repeat('a', 64) . '.test',
        ] as $name) {
            try {
                $this->within($loop, static fn(): array => $only->resolve($loop, $name));
                self::fail("{$name} was found.");
            } catch (RuntimeException $error) {
                self::assertMatchesRegularExpression(
                    '/^(no address was found for the name|".*" is not a host name)/',
                    $error->getMessage(),
                );
            }
        }
        self::assertSame(
            [],
            array_filter(
                $asked,
                static fn(string $question): bool => (
                    str_contains($question, 'not a name') || str_contains($question, 'bad')
                ),
            ),
            'What is not a host name is never sent.',
        );

        // Without name servers, and with the system's resolver allowed, a name it knows is still found.
        $system = new DnsResolver([], 0.05, 1, true, $this->hosts);
        self::assertNotSame(
            [],
            $this->within($loop, static fn(): array => $system->resolve(
                $loop,
                gethostname() !== false && @gethostbynamel(gethostname()) !== false ? gethostname() : 'box.test',
            )),
        );

        $cancellation = new Cancellation();
        $loop->delay(0.01, static fn() => $cancellation->cancel());
        $waiting = new DnsResolver([$silentAddress], 5.0, 1, false, $this->hosts);
        $this->expectException(CancelledException::class);
        $this->within($loop, static fn(): array => $waiting->resolve($loop, 'slow.test', $cancellation));
    }

    public function testCallersAskingForOneNameAtOnceShareOneQuestion(): void
    {
        $loop = $this->loop = $this->newLoop();
        $asked = [];
        $answer = null;
        $server = $this->nameServer(
            $loop,
            $asked,
            ['shared.test' => [[1, 60, "\x0A\x00\x00\x04"]]],
            hold: static function (\Closure $send) use (&$answer): void {
                $answer = $send;
            },
        );
        $dns = new DnsResolver([$server], 10.0, 1, false, $this->hosts);
        $tasks = [];
        for ($i = 0; $i < 5; ++$i) {
            $task = Task::spawn($loop, static fn(#[\SensitiveParameter] Cancellation $token): array => $dns->resolve(
                $loop,
                'shared.test',
                $token,
            ));
            $this->deferCleanup($task->cancel(...));
            $tasks[] = $task;
        }
        $found = $this->within(
            $loop,
            static function () use ($loop, $dns, $tasks, &$answer, &$asked): array {
                $asking = new \ReflectionProperty($dns, 'asking');
                while ($answer === null || count($asking->getValue($dns)['shared.test'] ?? []) !== 4) {
                    Async::sleep($loop, 0);
                }
                self::assertSame(
                    ['shared.test A'],
                    $asked,
                    'All four followers joined before any answer was released.',
                );
                foreach ($tasks as $task) {
                    self::assertFalse($task->isComplete());
                }
                $answer();
                return array_map(static fn(Task $task): array => $task->await(), $tasks);
            },
            'The DNS leader and four followers did not share and finish one question within five seconds.',
        );
        self::assertSame(array_fill(0, 5, ['10.0.0.4']), $found);
        self::assertSame(['shared.test A'], $asked);
    }

    public function testCancelledFollowersAreReleasedBeforeTheirLeaderAnswers(): void
    {
        $this->assertCancelledFollowersAreReleased('answer');
    }

    public function testCancelledFollowersAreReleasedWhileTheirLeaderCanStillBeCancelled(): void
    {
        $this->assertCancelledFollowersAreReleased('cancel');
    }

    public function testCancelledFollowersAreReleasedBeforeTheirLeaderFails(): void
    {
        $this->assertCancelledFollowersAreReleased('failure');
    }

    public function testExpiredAnswersAreReclaimedWhileLiveAnswersStayCached(): void
    {
        $loop = $this->loop = $this->newLoop();
        $asked = [];
        $records = ['live.test' => [[1, 300, "\x0A\x00\x00\x01"]]];
        for ($i = 0; $i < 60; ++$i) {
            $records["old-{$i}.test"] = [[1, 5, "\x0A\x00\x00\x02"]];
        }
        $server = $this->nameServer($loop, $asked, $records);
        $dns = new DnsResolver([$server], 10.0, 1, false, $this->hosts);
        self::assertSame(['10.0.0.1'], $this->within($loop, static fn(): array => $dns->resolve($loop, 'live.test')));

        for ($wave = 0; $wave < 3; ++$wave) {
            for ($i = $wave * 20; $i < (($wave + 1) * 20); ++$i) {
                self::assertSame(
                    ['10.0.0.2'],
                    $this->within($loop, static fn(): array => $dns->resolve($loop, "old-{$i}.test")),
                );
            }
            $this->expireAnswers($dns, except: 'live.test');
            self::assertSame(
                ['10.0.0.1'],
                $this->within($loop, static fn(): array => $dns->resolve($loop, 'LIVE.TEST.')),
            );
            $kept = new \ReflectionProperty($dns, 'kept')->getValue($dns);
            self::assertSame(['live.test'], array_keys($kept), 'Expired names must not accumulate between waves.');
            self::assertCount(1 + (($wave + 1) * 20), $asked, 'Maintenance must not refresh live answers.');
        }

        $dns->forget();
        self::assertSame([], new \ReflectionProperty($dns, 'kept')->getValue($dns));
        self::assertSame(['10.0.0.1'], $this->within($loop, static fn(): array => $dns->resolve($loop, 'live.test')));
        self::assertCount(62, $asked);
    }

    public function testAFailedLookupStillReclaimsExpiredAnswers(): void
    {
        $loop = $this->loop = $this->newLoop();
        $asked = [];
        $server = $this->nameServer($loop, $asked, ['old.test' => [[1, 5, "\x0A\x00\x00\x02"]]]);
        $dns = new DnsResolver([$server], 10.0, 1, false, $this->hosts);
        $this->within($loop, static fn(): array => $dns->resolve($loop, 'old.test'));
        $this->expireAnswers($dns);

        try {
            $this->within($loop, static fn(): array => $dns->resolve($loop, 'missing.test'));
            self::fail('An absent name was resolved.');
        } catch (RuntimeException $error) {
            self::assertSame('no address was found for the name missing.test', $error->getMessage());
        }
        self::assertSame([], new \ReflectionProperty($dns, 'kept')->getValue($dns));
    }

    /**
     * Hold one real DNS question while completed cancellations accumulate.
     * A late follower must still join it after all earlier followers left.
     */
    private function assertCancelledFollowersAreReleased(string $ending): void
    {
        $loop = $this->loop = $this->newLoop();
        $asked = [];
        $answer = null;
        $server = $this->nameServer(
            $loop,
            $asked,
            [
                'shared.test' => $ending === 'failure' ? 'failure' : [[1, 60, "\x0A\x00\x00\x04"]],
                'fresh.test' => [[1, 60, "\x0A\x00\x00\x05"]],
            ],
            hold: static function (\Closure $send) use (&$answer): void {
                if ($answer === null) {
                    $answer = $send;
                } else {
                    $send();
                }
            },
        );
        $dns = new DnsResolver([$server], 10.0, 1, false, $this->hosts);
        $watchers = $loop->getIdentifiers();
        $this->within(
            $loop,
            function () use ($loop, $dns, $ending, &$answer, &$asked): void {
                $leader = Task::spawn($loop, static fn(Cancellation $cancellation): array => $dns->resolve(
                    $loop,
                    'shared.test',
                    $cancellation,
                ));
                $this->deferCleanup($leader->cancel(...));
                while ($answer === null) {
                    Async::sleep($loop, 0);
                }
                $asking = new \ReflectionProperty($dns, 'asking');
                $retained = [];
                for ($wave = 0; $wave < 3; ++$wave) {
                    for ($i = 0; $i < 10; ++$i) {
                        $follower = Task::spawn($loop, static fn(Cancellation $cancellation): array => $dns->resolve(
                            $loop,
                            'shared.test',
                            $cancellation,
                        ));
                        $this->deferCleanup($follower->cancel(...));
                        $loop->defer(static function () use ($dns, $asking, $follower): void {
                            self::assertFalse($follower->isComplete());
                            self::assertNotEmpty($asking->getValue($dns)['shared.test']);
                            $follower->cancel();
                        });
                        $error = null;
                        try {
                            $follower->await();
                        } catch (CancelledException $cancelled) {
                            $error = $cancelled;
                        }
                        self::assertInstanceOf(CancelledException::class, $error);
                    }
                    self::assertArrayHasKey(
                        'shared.test',
                        $asking->getValue($dns),
                        'The leader still owns the lookup.',
                    );
                    $retained[] = count($asking->getValue($dns)['shared.test']);
                    self::assertFalse($leader->isComplete());
                }
                $before = count($asking->getValue($dns)['shared.test']);
                $live = Task::spawn($loop, static fn(): array => $dns->resolve($loop, 'SHARED.TEST.'));
                $this->deferCleanup($live->cancel(...));
                while (count($asking->getValue($dns)['shared.test']) === $before) {
                    Async::sleep($loop, 0);
                }
                self::assertFalse($live->isComplete());
                self::assertSame(['shared.test A'], $asked, 'A later caller must join the original question.');
                if ($ending === 'cancel') {
                    $leader->cancel();
                } else {
                    $answer();
                }
                foreach ([$leader, $live] as $task) {
                    $result = null;
                    $error = null;
                    try {
                        $result = $task->await();
                    } catch (\Throwable $failure) {
                        $error = $failure;
                    }
                    if ($ending === 'answer') {
                        self::assertNull($error);
                        self::assertSame(['10.0.0.4'], $result);
                    } elseif ($ending === 'cancel' && $task === $leader) {
                        self::assertInstanceOf(CancelledException::class, $error);
                    } else {
                        self::assertInstanceOf(RuntimeException::class, $error);
                        self::assertSame('no address was found for the name shared.test', $error->getMessage());
                    }
                }
                self::assertSame([], $asking->getValue($dns));
                if ($ending === 'answer') {
                    self::assertSame(['10.0.0.4'], $dns->resolve($loop, 'shared.test'));
                    self::assertSame(['shared.test A'], $asked, 'The answer remains cached.');
                }
                self::assertSame(['10.0.0.5'], $dns->resolve($loop, 'fresh.test'));
                self::assertSame(['shared.test A', 'fresh.test A'], $asked);
                self::assertSame([], $asking->getValue($dns));
                self::assertSame(
                    [0, 0, 0],
                    $retained,
                    'Completed cancellations must not accumulate while DNS is pending.',
                );
            },
            'The held DNS lookup or its followers did not settle within five seconds.',
        );
        self::assertSame($watchers, $loop->getIdentifiers(), 'Every lookup watcher and timer was released.');
        self::assertSame([], $this->reports);
    }

    /**
     * Move stored deadlines into the past without sleeping through real TTLs.
     * The optional sweep deadline is rewound too, so maintenance is due now.
     */
    private function expireAnswers(DnsResolver $dns, ?string $except = null): void
    {
        $property = new \ReflectionProperty($dns, 'kept');
        $kept = $property->getValue($dns);
        foreach (array_keys($kept) as $name) {
            if ($name === $except) {
                continue;
            }
            $kept[$name][1] = 0.0;
        }
        $property->setValue($dns, $kept);
        if (property_exists($dns, 'pruneAt')) {
            new \ReflectionProperty($dns, 'pruneAt')->setValue($dns, 0.0);
        }
    }

    /**
     * Runs code that waits on the loop, in a task, and returns what it returned.
     */
    private function within(EventLoop $loop, \Closure $body, string $failure = 'The test exceeded its deadline.'): mixed
    {
        $task = Task::spawn($loop, static fn(): mixed => $body());
        $this->deferCleanup($task->cancel(...));
        $deadline = $loop->delay(5, static fn() => throw new RuntimeException($failure));
        try {
            return $task->await();
        } finally {
            $loop->cancel($deadline);
        }
    }

    private function newLoop(): EventLoop
    {
        $loop = RevoltEventLoop::create();
        $this->deferCleanup(static function () use ($loop): void {
            foreach ($loop->getIdentifiers() as $id) {
                $loop->cancel($id);
            }
        });
        return $loop;
    }

    /**
     * A name server on the loop that answers from a list.
     *
     * @param list<string> $asked Filled with each question: "name A" or "name AAAA".
     * @param array<string, list<array{int, int, string}>|string> $records By name: records as type, seconds they hold and data; or "truncated" or "failure".
     * @param (\Closure(\Closure): void)|null $hold Holds a prepared reply until the test's callers have joined.
     * @return string Its address and port.
     */
    private function nameServer(EventLoop $loop, array &$asked, array $records, ?\Closure $hold = null): string
    {
        $socket = stream_socket_server('udp://127.0.0.1:0', $code, $reason, STREAM_SERVER_BIND);
        stream_set_blocking($socket, false);
        $this->sockets[] = $socket;
        $this->watchers[] = $loop->onReadable($socket, static function () use (
            $loop,
            $socket,
            $records,
            $hold,
            &$asked,
        ): void {
            $query = stream_socket_recvfrom($socket, 4096, 0, $from);
            if ($query === false || strlen($query) < 12) {
                return;
            }
            $offset = 12;
            $labels = [];
            while (($size = ord($query[$offset])) !== 0) {
                $labels[] = substr($query, $offset + 1, $size);
                $offset += 1 + $size;
            }
            $name = implode('.', $labels);
            $type = unpack('n', $query, $offset + 1)[1];
            $asked[] = $name . ($type === 1 ? ' A' : ' AAAA');
            $question = substr($query, 12, $offset + 5 - 12);
            $known = $records[$name] ?? null;
            $flags = match (true) {
                $known === 'truncated' => 0x8380,
                $known === 'failure' => 0x8182,
                $known === null => 0x8183,
                default => 0x8180,
            };
            $answers = '';
            $count = 0;
            foreach (is_array($known) ? $known : [] as [$kind, $holds, $data]) {
                if (!($kind === $type || $kind === 5)) {
                    continue;
                }

                // The name is given as a pointer to the question's.
                $answers .= "\xC0\x0C" . pack('nnNn', $kind, 1, $holds, strlen($data)) . $data;
                ++$count;
            }
            $reply = substr($query, 0, 2) . pack('nnnnn', $flags, 1, $count, 0, 0) . $question . $answers;
            $send = static function () use ($socket, $reply, $from): void {
                @stream_socket_sendto($socket, $reply, 0, $from);
            };
            // A reply to another question arrives first and must not be taken for this one's.
            @stream_socket_sendto($socket, "\x00\x00" . substr($reply, 2), 0, $from);
            if ($hold !== null) {
                $hold($send);
            } else {
                $send();
            }
        });
        return (string) stream_socket_get_name($socket, false);
    }
}
