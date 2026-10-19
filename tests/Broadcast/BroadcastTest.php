<?php

declare(strict_types=1);

namespace Crab\Tests\Broadcast;

use Crab\App;
use Crab\Broadcast\Broadcaster;
use Crab\Broadcast\BroadcastProvider;
use Crab\Broadcast\Channels;
use Crab\Broadcast\Lifecycle;
use Crab\Broadcast\LocalCarrier;
use Crab\Config\Config;
use Crab\Container\Container;
use Crab\Contracts\Broadcast\Broadcaster as BroadcasterContract;
use Crab\Contracts\Broadcast\Carrier;
use Crab\Contracts\Config\Config as ConfigContract;
use Crab\Contracts\Database\Connection;
use Crab\Contracts\WebSocket\Recipient;
use Crab\Contracts\WebSocket\Socket;
use Crab\Database\PdoConnection;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\RevoltEventLoop;
use Crab\Engine\FailureReporter;
use Crab\Engine\Network\WriteResult;
use Crab\Testing\Api;
use Crab\Testing\FakeBroadcaster;
use Crab\Testing\WebSocketRefused;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Broadcast behaviour with no listening ports or external stores. Socket
 * pairs exercise the actual upgrade, action lifetime and output path.
 */
final class BroadcastTest extends TestCase
{
    private EventLoop $loop;
    private ?\Closure $reporter;
    private array $reports = [];
    private array $channels = [];
    private array $apis = [];

    protected function setUp(): void
    {
        $this->loop = EventLoop::install(RevoltEventLoop::create());
        $this->reporter = FailureReporter::handleWith(function (string $what, ?\Throwable $error): void {
            $this->reports[] = [$what, $error];
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->apis as $api) {
            $api->closeSockets();
        }
        foreach ($this->channels as $channels) {
            $channels->close();
        }
        $this->loop->defer($this->loop->stop(...));
        $this->loop->run();
        self::assertSame([], $this->loop->getIdentifiers(), 'Broadcast left a loop watcher after stop.');
        FailureReporter::handleWith($this->reporter);
    }

    private function channels(
        Carrier $carrier,
        int $limit = 64,
        int $batch = 128,
        int $pending = 1_048_576,
        int $backlog = 524_288,
    ): Channels {
        return $this->channels[] = new Channels($carrier, $limit, $batch, $pending, $backlog);
    }

    private function waitUntil(string $what, \Closure $ready, float $seconds = 1.0): void
    {
        $deadline = microtime(true) + $seconds;
        while (!$ready()) {
            if (microtime(true) >= $deadline) {
                self::fail("Timed out waiting for {$what}.");
            }
            \Crab\Engine\Async\Async::sleep($this->loop, 0.001);
        }
    }

    public function testJoiningTwiceAndLeavingAnUnknownChannelAreHarmless(): void
    {
        $carrier = new SpyCarrier();
        $channels = $this->channels($carrier);
        $socket = new TestRecipient();
        $channels->join($socket, 'orders.42');
        $channels->join($socket, 'orders.42');
        $channels->leave($socket, 'orders.99');
        self::assertSame(['orders.42'], $carrier->joined);
        self::assertSame(1, $channels->stats()['connections']);
        $channels->forget($socket);
        self::assertSame(['orders.42'], $carrier->left);
        self::assertSame(0, $channels->stats()['channels']);
        self::assertSame(0, $channels->stats()['connections']);
    }

    public function testOnlyJoinedChannelsReceiveMessagesInOrder(): void
    {
        $carrier = new LocalCarrier();
        $channels = $this->channels($carrier);
        $a = new TestRecipient();
        $b = new TestRecipient();
        $channels->join($a, 'orders.1');
        $channels->join($a, 'orders.2');
        $channels->join($b, 'orders.3');
        $broadcast = new Broadcaster($carrier);
        $broadcast->to('orders.1')->send(['step' => 1]);
        $broadcast->to('orders.1', 'orders.2', 'orders.2')->send('next');
        $this->loop->run();
        self::assertSame(['{"step":1}', 'next', 'next'], $a->messages);
        self::assertSame([], $b->messages);
        $channels->leave($a, 'orders.1');
        $broadcast->to('orders.1')->send('gone');
        $broadcast->to('orders.2')->send('still here');
        $this->loop->run();
        self::assertSame('still here', end($a->messages));
        self::assertCount(4, $a->messages);
    }

    public function testLeavingAChannelBeforeItsFanOutBatchSkipsPendingMessages(): void
    {
        $carrier = new LocalCarrier();
        $channels = $this->channels($carrier, batch: 2);
        $sockets = [];
        for ($index = 0; $index < 4; ++$index) {
            $sockets[] = $socket = new TestRecipient();
            $channels->join($socket, 'orders');
        }
        $leaving = $sockets[3];
        $channels->join($leaving, 'quiet');
        $broadcast = new Broadcaster($carrier);
        $broadcast->to('orders')->send('first');
        $broadcast->to('orders')->send('second');
        $beforeLeave = new \ArrayObject();
        $this->loop->defer(static function () use ($channels, $sockets, $leaving, $beforeLeave): void {
            $beforeLeave[] = [$sockets[0]->messages, $leaving->messages, $channels->stats()['pending_bytes']];
            $channels->leave($leaving, 'orders');
        });
        $this->waitUntil(
            'fan-out queued before leaving orders to finish',
            static fn(): bool => $channels->stats()['pending_bytes'] === 0,
        );

        self::assertSame(['first'], $beforeLeave[0][0]);
        self::assertSame([], $beforeLeave[0][1]);
        self::assertGreaterThan(0, $beforeLeave[0][2]);
        foreach (array_slice($sockets, 0, 3) as $socket) {
            self::assertSame(['first', 'second'], $socket->messages);
        }
        self::assertSame(
            [],
            $leaving->messages,
            'Pending deliveries must check membership in their own channel before writing.',
        );
        $broadcast->to('quiet')->send('still joined');
        $this->waitUntil('delivery to the remaining quiet membership', static fn(): bool => $leaving->messages !== []);
        self::assertSame(['still joined'], $leaving->messages);
        self::assertNull($leaving->ended);
    }

    public function testLeavingTheLastChannelDoesNotRecreateABacklogFromPendingFanOut(): void
    {
        $carrier = new LocalCarrier();
        $channels = $this->channels($carrier, batch: 2);
        $fast = [];
        for ($index = 0; $index < 3; ++$index) {
            $fast[] = $socket = new TestRecipient();
            $channels->join($socket, 'orders');
        }
        $leaving = new TestRecipient(WriteResult::Backpressured);
        $channels->join($leaving, 'orders');
        $broadcast = new Broadcaster($carrier)->to('orders');
        $broadcast->send('already behind');
        $this->waitUntil(
            'the recipient backlog before leaving its last channel',
            static fn(): bool => $channels->stats()['backlog_bytes'] > 0,
        );
        $broadcast->send('first pending');
        $broadcast->send('second pending');
        $afterLeave = new \ArrayObject();
        $this->loop->defer(static function () use ($channels, $fast, $leaving, $afterLeave): void {
            $channels->leave($leaving, 'orders');
            $afterLeave[] = [$fast[0]->messages, $channels->stats()];
        });
        $this->waitUntil(
            'fan-out queued before leaving the last channel to finish',
            static fn(): bool => $channels->stats()['pending_bytes'] === 0,
        );
        // Observe past the recipient's 20 ms timeout to catch a recreated backlog.
        \Crab\Engine\Async\Async::sleep($this->loop, 0.03);

        self::assertSame(['already behind', 'first pending'], $afterLeave[0][0]);
        self::assertGreaterThan(0, $afterLeave[0][1]['pending_bytes']);
        self::assertSame(
            0,
            $afterLeave[0][1]['backlog_bytes'],
            'Leaving the last channel must discard the existing backlog.',
        );
        self::assertNull(
            $leaving->ended,
            'Pending fan-out must not recreate a backlog and close a recipient that left.',
        );
        self::assertSame([], $leaving->messages);
        self::assertSame(0, $channels->stats()['backlog_bytes']);
        self::assertSame(0, $channels->stats()['slow_clients']);
        self::assertSame(3, $channels->stats()['connections']);
        foreach ($fast as $socket) {
            self::assertSame(['already behind', 'first pending', 'second pending'], $socket->messages);
        }
    }

    public function testABroadcastIsEncodedOnceBeforeTheCarrierSeesIt(): void
    {
        $carrier = new SpyCarrier();
        $broadcast = new Broadcaster($carrier);
        $counter = new EncodingCounter();
        $broadcast->to('a', 'b')->send(['value' => $counter]);
        self::assertSame(1, $counter->calls);
        self::assertSame([[['a', 'b'], '{"value":"once"}']], $carrier->sent);
    }

    public function testASlowClientClosesWithoutDelayingAnotherClient(): void
    {
        $carrier = new LocalCarrier();
        $channels = $this->channels($carrier);
        $slow = new TestRecipient(WriteResult::Backpressured);
        $fast = new TestRecipient();
        $channels->join($slow, 'orders');
        $channels->join($fast, 'orders');
        new Broadcaster($carrier)->to('orders')->send('hello');
        self::assertSame([], $fast->messages, 'The sender should only hand off the fan-out.');
        $this->loop->run();
        self::assertSame([1008, 'Read messages faster'], $slow->ended);
        self::assertSame(['hello'], $fast->messages);
        self::assertSame(1, $channels->stats()['slow_clients']);
        self::assertSame(1, $channels->stats()['connections']);
    }

    public function testFanOutYieldsSoAnotherTimerCanRun(): void
    {
        $carrier = new LocalCarrier();
        $channels = $this->channels($carrier, batch: 32);
        $sockets = [];
        for ($index = 0; $index < 1000; ++$index) {
            $sockets[] = $socket = new TestRecipient();
            $channels->join($socket, 'orders');
        }
        $probe = new \ArrayObject();
        new Broadcaster($carrier)->to('orders')->send('hello');
        $this->loop->delay(0, static function () use ($sockets, $probe): void {
            $probe[] = count(array_filter(
                $sockets,
                static fn(TestRecipient $socket): bool => $socket->messages !== [],
            ));
        });
        $this->loop->run();
        self::assertGreaterThan(0, $probe[0]);
        self::assertLessThan(1000, $probe[0]);
        self::assertSame(['hello'], $sockets[999]->messages);
    }

    public function testChannelNamesAndMembershipLimitsSayHowToFixThem(): void
    {
        $carrier = new LocalCarrier();
        $channels = $this->channels($carrier, limit: 1);
        $socket = new TestRecipient();
        foreach ([
            '',
            str_repeat('a', 201),
            "bad\nname",
            "bad\x7fname",
            "bad\u{0085}name",
            "bad\u{202e}name",
            "\xff",
        ] as $name) {
            try {
                new Broadcaster($carrier)->to($name);
                self::fail('An invalid name was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('1 to 200 bytes without control characters', $error->getMessage());
            }
        }
        $channels->join($socket, str_repeat('a', 200));
        $this->expectException(\LengthException::class);
        $this->expectExceptionMessage('Leave one or increase broadcast.max_channels');
        $channels->join($socket, 'another');
    }

    public function testInvalidTextAndAMissingSelectionAreRefusedBeforePublishing(): void
    {
        $carrier = new SpyCarrier();
        foreach ([
            static fn() => new Broadcaster($carrier)->send('hi'),
            static fn() => new Broadcaster($carrier)->to(),
            static fn() => new Broadcaster($carrier)->to('a')->send("\xff"),
        ] as $send) {
            try {
                $send();
                self::fail('An invalid send was accepted.');
            } catch (\LogicException|\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        self::assertSame([], $carrier->sent);
    }

    public function testAGapClosesJoinedClientsAndDiscardsPendingMessages(): void
    {
        $carrier = new SpyCarrier();
        $channels = $this->channels($carrier);
        $socket = new TestRecipient();
        $channels->join($socket, 'orders');
        ($carrier->receive)('orders', 'stale');
        ($carrier->gap)();
        $this->loop->run();
        self::assertSame([1012, 'Reconnect and refetch state'], $socket->ended);
        self::assertSame([], $socket->messages);
        self::assertSame(0, $channels->stats()['channels']);
        self::assertSame(0, $channels->stats()['pending_bytes']);
    }

    public function testAFullFanOutQueueClosesClientsInsteadOfGrowing(): void
    {
        $carrier = new LocalCarrier();
        $channels = $this->channels($carrier, pending: 257);
        $socket = new TestRecipient();
        $channels->join($socket, 'orders');
        $broadcast = new Broadcaster($carrier)->to('orders');
        $broadcast->send('a');
        try {
            $broadcast->send('b');
            self::fail('The pending byte limit was ignored.');
        } catch (\OverflowException $error) {
            self::assertStringContainsString('broadcast.max_pending_bytes', $error->getMessage());
        }
        self::assertSame(1012, $socket->ended[0]);
        self::assertSame(0, $channels->stats()['pending_bytes']);
    }

    private function database(): PdoConnection
    {
        return new PdoConnection(static fn(): \PDO => new \PDO('sqlite::memory:'));
    }

    public function testACommitAnnouncesOnlyAfterTheOutermostTransaction(): void
    {
        $carrier = new SpyCarrier();
        $broadcast = new Broadcaster($carrier);
        $db = $this->database();
        $db->transaction(static function (Connection $tx) use ($broadcast, $carrier): void {
            $broadcast->within($tx)->to('orders')->send('outer');
            $tx->transaction(static fn(Connection $inner) => $broadcast->to('orders')->within($inner)->send('inner'));
            self::assertSame([], $carrier->sent);
        });
        self::assertSame([[['orders'], 'outer'], [['orders'], 'inner']], $carrier->sent);
    }

    public function testRollbackAndARolledBackNestedTransactionNeverAnnounce(): void
    {
        $carrier = new SpyCarrier();
        $broadcast = new Broadcaster($carrier);
        $db = $this->database();
        $db->transaction(static function (Connection $tx) use ($broadcast): void {
            $broadcast->within($tx)->to('orders')->send('kept');
            try {
                $tx->transaction(static function (Connection $inner) use ($broadcast): void {
                    $broadcast->within($inner)->to('orders')->send('discarded');
                    throw new \DomainException('undo inner');
                });
            } catch (\DomainException) {
            }
        });
        try {
            $db->transaction(static function (Connection $tx) use ($broadcast): void {
                $broadcast->within($tx)->to('orders')->send('discarded outer');
                throw new \DomainException('undo outer');
            });
        } catch (\DomainException) {
        }
        self::assertSame([[['orders'], 'kept']], $carrier->sent);
    }

    public function testRetryingAFailedTransactionAnnouncesOnlyItsSuccessfulAttempt(): void
    {
        $carrier = new SpyCarrier();
        $broadcast = new Broadcaster($carrier);
        $db = $this->database();
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            try {
                $db->transaction(static function (Connection $tx) use ($broadcast, $attempt): void {
                    $broadcast
                        ->within($tx)
                        ->to('orders')
                        ->send('attempt ' . $attempt);
                    if ($attempt === 1) {
                        throw new \DomainException('retry the whole transaction');
                    }
                });
            } catch (\DomainException) {
            }
        }
        self::assertSame([[['orders'], 'attempt 2']], $carrier->sent);
    }

    public function testARealDeadlockFailureDiscardsAnnouncementsBeforeTheWholeCallbackIsRetried(): void
    {
        $deadlock = new \PDOException('Deadlock found when trying to get lock');
        $deadlock->errorInfo = ['40001', 1213, 'Deadlock'];
        $attempts = new \ArrayObject([0]);
        $statement = $this->createStub(\PDOStatement::class);
        $statement
            ->method('execute')
            ->willReturnCallback(static function () use ($attempts, $deadlock): bool {
                if (++$attempts[0] === 1) {
                    throw $deadlock;
                }
                return true;
            });
        $statement->method('rowCount')->willReturn(1);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');
        $pdo->method('setAttribute')->willReturn(true);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('prepare')->willReturn($statement);
        $db = new PdoConnection(static fn(): \PDO => $pdo);
        $carrier = new SpyCarrier();
        $broadcast = new Broadcaster($carrier);
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            try {
                $db->transaction(static function (Connection $tx) use ($broadcast, $attempt): void {
                    $broadcast
                        ->within($tx)
                        ->to('orders')
                        ->send('outer ' . $attempt);
                    $tx->transaction(static function (Connection $inner) use ($broadcast, $attempt): void {
                        $broadcast
                            ->within($inner)
                            ->to('orders')
                            ->send('inner ' . $attempt);
                        $inner->execute('UPDATE orders SET status = 1');
                    });
                });
            } catch (\Crab\Database\DatabaseException $error) {
                self::assertSame(1213, $error->code());
            }
        }
        self::assertSame([[['orders'], 'outer 2'], [['orders'], 'inner 2']], $carrier->sent);
    }

    public function testAStalledRealReaderClosesWithinTheSendTimeoutWithoutDelayingOthers(): void
    {
        $settings = new \Crab\WebSocket\Settings(ping: 0, maxMessage: 1024, sendTimeout: 0.03);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $transport = new \Crab\Engine\Network\Connection($this->loop, $pair[0], maxWriteBufferSize: 4096);
        $session = new \Crab\Engine\WebSocket\WebSocketSession(
            $transport,
            static function (): void {},
            $settings->engine,
        );
        $slow = new \Crab\WebSocket\Socket(
            $this->loop,
            new \Crab\Http\Request('GET', '/live'),
            static function (): void {},
            $settings,
        );
        $slow->attach($session, $transport);
        $carrier = new LocalCarrier();
        $channels = $this->channels($carrier);
        $fast = new TestRecipient();
        $channels->join($slow, 'orders');
        $channels->join($fast, 'orders');
        try {
            for ($round = 0; $round < 1000; ++$round) {
                for ($index = 0; $index < 1000; ++$index) {
                    if ($session->sendText(str_repeat('x', 1024)) === WriteResult::Backpressured) {
                        break;
                    }
                }
                self::assertLessThan(1000, $index, 'The output queue must be bounded.');
                $this->loop->defer($this->loop->stop(...));
                $this->loop->run();
                if (($session->bufferedBytes() + 1024) > $settings->engine->maxQueuedOutputBytes) {
                    break;
                }
            }
            self::assertLessThan(1000, $round, 'The peer kernel buffers should eventually fill.');
            new Broadcaster($carrier)->to('orders')->send(str_repeat('x', 1024));
            $probe = new \ArrayObject();
            $this->loop->delay(0, function () use ($probe): void {
                $probe[] = true;
                $this->loop->stop();
            });
            $this->loop->run();
            self::assertFalse($slow->isClosed(), 'A full output queue alone is not evidence of a slow reader.');
            self::assertSame([str_repeat('x', 1024)], $fast->messages);
            self::assertSame(0, $channels->stats()['slow_clients']);
            self::assertSame(1280, $channels->stats()['backlog_bytes']);
            self::assertSame([true], $probe->getArrayCopy());
            $this->waitUntil('the stalled reader send timeout', $slow->isClosed(...), 0.1);
            self::assertSame(1, $channels->stats()['slow_clients']);
            self::assertSame(0, $channels->stats()['backlog_bytes']);
        } finally {
            $transport->close();
            fclose($pair[1]);
        }
    }

    public function testAReadingClientReceivesSixMaximumSizedMessagesInOneBurst(): void
    {
        $app = new App(new Config(['websocket' => ['ping' => 0]]));
        $app->register(...App::PROVIDERS);
        $app->router->websocket('/live', static function (Socket $socket): void {
            $socket->join('orders');
            $socket->send('ready');
            foreach ($socket as $message) {
            }
        });
        $api = $this->apis[] = new Api($app);
        $client = $api->websocket('/live');
        $client->assertReceived('ready');
        $channels = $this->channels[] = $app->container->get(Channels::class);
        $broadcast = $app->container->get(BroadcasterContract::class)->to('orders');
        for ($index = 0; $index < 6; ++$index) {
            $broadcast->send($index . str_repeat('x', 65_535));
        }
        for ($index = 0; $index < 6; ++$index) {
            $client->assertReceived($index . str_repeat('x', 65_535));
        }
        $this->waitUntil('the burst backlog to drain', static fn() => $channels->stats()['backlog_bytes'] === 0);
        self::assertSame(0, $channels->stats()['slow_clients']);
        self::assertSame(1, $channels->stats()['connections']);
    }

    public function testNewBroadcastsStayBehindTheRecipientsBacklogInOrder(): void
    {
        $carrier = new LocalCarrier();
        $channels = $this->channels($carrier);
        $socket = new TestRecipient(WriteResult::Backpressured);
        $channels->join($socket, 'orders');
        $broadcast = new Broadcaster($carrier)->to('orders');
        $broadcast->send('first');
        $this->waitUntil(
            'the first backpressured broadcast to queue',
            static fn() => $channels->stats()['backlog_bytes'] > 0,
        );
        $socket->result = WriteResult::Accepted;
        $broadcast->send('second');
        $broadcast->send('third');
        $this->waitUntil('the recipient to catch up', static fn() => count($socket->messages) === 3);
        self::assertSame(['first', 'second', 'third'], $socket->messages);
        self::assertSame(0, $channels->stats()['slow_clients']);
        self::assertNull($socket->ended);
    }

    public function testThePerRecipientBacklogBoundsBytesAndEmptyMessageMetadata(): void
    {
        foreach (['xx', ''] as $message) {
            $carrier = new LocalCarrier();
            $channels = $this->channels($carrier, backlog: 600);
            $slow = new TestRecipient(WriteResult::Backpressured);
            $fast = new TestRecipient();
            $channels->join($slow, 'orders');
            $channels->join($fast, 'orders');
            $broadcast = new Broadcaster($carrier)->to('orders');
            $broadcast->send($message);
            $broadcast->send($message);
            $this->waitUntil(
                'two messages to queue within the backlog bound',
                static fn() => $channels->stats()['backlog_bytes'] === (2 * (strlen($message) + 256)),
            );
            self::assertLessThanOrEqual(600, $channels->stats()['backlog_bytes']);
            self::assertNull($slow->ended);
            $broadcast->send($message);
            $this->waitUntil(
                'the backlog overflow to close only the slow recipient',
                static fn() => $slow->ended !== null,
            );
            self::assertSame([1008, 'Read messages faster'], $slow->ended);
            self::assertSame([$message, $message, $message], $fast->messages);
            self::assertSame(1, $channels->stats()['slow_clients']);
            self::assertSame(0, $channels->stats()['backlog_bytes']);
            self::assertSame(1, $channels->stats()['connections']);
        }
    }

    public function testAGapContinuesPastARecipientWhoseCloseThrowsAndReleasesEveryMembership(): void
    {
        $carrier = new SpyCarrier();
        $channels = $this->channels($carrier);
        $broken = new TestRecipient();
        $broken->closeFailure = new \RuntimeException('close failed');
        $healthy = new TestRecipient();
        $channels->join($broken, 'orders');
        $channels->join($broken, 'another');
        $channels->join($healthy, 'quiet');
        ($carrier->gap)();
        self::assertSame([1012, 'Reconnect and refetch state'], $healthy->ended);
        self::assertSame(0, $channels->stats()['connections']);
        self::assertSame(0, $channels->stats()['channels']);
        self::assertCount(1, $this->reports);
        self::assertSame($broken->closeFailure, $this->reports[0][1]);
    }

    public function testForgettingContinuesPastAnUnsubscribeFailure(): void
    {
        $carrier = new SpyCarrier();
        $carrier->leaveFailure = new \RuntimeException('unsubscribe failed');
        $channels = $this->channels($carrier);
        $socket = new TestRecipient();
        $channels->join($socket, 'orders');
        $channels->join($socket, 'another');
        $channels->forget($socket);
        self::assertSame(['orders', 'another'], $carrier->left);
        self::assertSame(0, $channels->stats()['connections']);
        self::assertSame(0, $channels->stats()['channels']);
        self::assertCount(2, $this->reports);
    }

    public function testARecipientFailureDoesNotAbortFanOutWhenItsCloseAlsoThrows(): void
    {
        $carrier = new LocalCarrier();
        $channels = $this->channels($carrier);
        $broken = new TestRecipient();
        $broken->broadcastFailure = new \LengthException('update is too large');
        $broken->closeFailure = new \RuntimeException('close failed');
        $healthy = new TestRecipient();
        $channels->join($broken, 'orders');
        $channels->join($healthy, 'orders');
        new Broadcaster($carrier)->to('orders')->send('hello');
        $this->waitUntil('fan-out to continue after the failed recipient', static fn() => $healthy->messages !== []);
        self::assertSame(['hello'], $healthy->messages);
        self::assertSame(1, $channels->stats()['connections']);
        self::assertCount(1, $this->reports);
        self::assertSame($broken->broadcastFailure, $this->reports[0][1]);
    }

    public function testStoppingDiscardsARecipientBacklogAndCancelsItsWaitAndTimer(): void
    {
        $carrier = new LocalCarrier();
        $channels = $this->channels($carrier);
        $slow = new TestRecipient(WriteResult::Backpressured);
        $channels->join($slow, 'orders');
        new Broadcaster($carrier)->to('orders')->send('hello');
        $this->waitUntil(
            'the backpressured recipient to start waiting',
            static fn() => $channels->stats()['backlog_bytes'] > 0,
        );
        $channels->close();
        self::assertSame(0, $channels->stats()['backlog_bytes']);
        self::assertSame([], $this->loop->getIdentifiers());
        self::assertSame(0, $channels->stats()['slow_clients']);
    }

    public function testPartialProgressDoesNotRestartTheBacklogDeadline(): void
    {
        $carrier = new LocalCarrier();
        $channels = $this->channels($carrier);
        $slow = new TestRecipient(WriteResult::Backpressured);
        $channels->join($slow, 'orders');
        $broadcast = new Broadcaster($carrier)->to('orders');
        $broadcast->send('first');
        $broadcast->send('second');
        $this->waitUntil(
            'the backlog to start its single deadline',
            static fn() => $channels->stats()['backlog_bytes'] > 0,
        );
        $slow->writableDelay = 0.015;
        $slow->result = WriteResult::Accepted;
        $this->waitUntil(
            'the original backlog deadline despite partial progress',
            static fn() => $slow->ended !== null,
            0.1,
        );
        self::assertSame(['first'], $slow->messages);
        self::assertSame([1008, 'Read messages faster'], $slow->ended);
        self::assertSame(1, $channels->stats()['slow_clients']);
    }

    public function testAFailedAnnouncementAfterCommitIsReportedWithoutFailingTheCaller(): void
    {
        $carrier = new SpyCarrier();
        $carrier->failure = new \RuntimeException('carrier unavailable');
        $db = $this->database();
        $db->execute('CREATE TABLE orders (id INTEGER)');
        $broadcast = new Broadcaster($carrier);
        $result = $db->transaction(static function (Connection $tx) use ($broadcast): string {
            $tx->execute('INSERT INTO orders VALUES (42)');
            $broadcast->within($tx)->to('orders')->send('shipped');
            return 'committed';
        });
        self::assertSame('committed', $result);
        self::assertSame(['id' => 42], $db->first('SELECT id FROM orders'));
        self::assertCount(1, $this->reports);
        self::assertSame($carrier->failure, $this->reports[0][1]);
    }

    public function testTheFakeRecordsSelectionsAndWaitsForCommit(): void
    {
        $fake = new FakeBroadcaster();
        $fake->assertNothingSent();
        $db = $this->database();
        $db->transaction(static function (Connection $tx) use ($fake): void {
            $fake->within($tx)->to('orders.42')->send(['status' => 'shipped']);
            $fake->assertNothingSent();
        });
        $fake->assertSent('orders.42', ['status' => 'shipped']);
        $fake->to('a', 'b')->send('text');
        $fake->assertSent('a', 'text');
        $fake->assertSent('b', 'text');
        $discarded = new FakeBroadcaster();
        try {
            $db->transaction(static function (Connection $tx) use ($discarded): void {
                $discarded->within($tx)->to('orders.42')->send('discarded');
                throw new \DomainException('undo');
            });
        } catch (\DomainException) {
        }
        $discarded->assertNothingSent();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mutableMessages(): iterable
    {
        foreach (['reference', 'object'] as $kind) {
            foreach (['immediate', 'commit', 'rollback'] as $when) {
                yield $kind . ' ' . $when => [$kind, $when];
            }
        }
    }

    #[DataProvider('mutableMessages')]
    public function testTheFakeKeepsTheSameMessageSnapshotAsTheCarrier(string $kind, string $when): void
    {
        $carrier = new SpyCarrier();
        $real = new Broadcaster($carrier);
        $fake = new FakeBroadcaster();
        $text = 'before';
        $object = (object) ['text' => 'before'];
        $message = ['nested' => $kind === 'reference' ? ['text' => &$text] : $object];
        $send = static function (?Connection $transaction = null) use (
            $real,
            $fake,
            $carrier,
            $message,
            $object,
            &$text,
        ): void {
            foreach ([$real, $fake] as $broadcaster) {
                $selected = $transaction === null ? $broadcaster : $broadcaster->within($transaction);
                $selected->to('orders')->send($message);
            }
            $text = 'after';
            $object->text = 'after';
            if ($transaction !== null) {
                self::assertSame([], $carrier->sent);
                $fake->assertNothingSent();
            }
        };

        if ($when === 'immediate') {
            $send();
        } else {
            $database = $this->database();
            try {
                $database->transaction(static function (Connection $transaction) use ($send, $when): void {
                    $send($transaction);
                    if ($when === 'rollback') {
                        throw new \DomainException('undo');
                    }
                });
            } catch (\DomainException $error) {
                self::assertSame('rollback', $when);
                self::assertSame('undo', $error->getMessage());
            } finally {
                $database->close();
            }
        }

        if ($when === 'rollback') {
            self::assertSame([], $carrier->sent);
            $fake->assertNothingSent();
            return;
        }
        self::assertSame([[['orders'], '{"nested":{"text":"before"}}']], $carrier->sent);
        $fake->assertSent('orders', ['nested' => ['text' => 'before']]);
        $failure = null;
        try {
            $fake->assertSent('orders', ['nested' => ['text' => 'after']]);
        } catch (AssertionFailedError $error) {
            $failure = $error;
        }
        self::assertInstanceOf(AssertionFailedError::class, $failure, 'The mutated value was never sent.');
    }

    public function testTheProviderCostsNoWatcherWhenUnusedAndWorksWithoutApp(): void
    {
        $container = new Container();
        $container->instance(ConfigContract::class, new Config());
        $provider = new BroadcastProvider();
        $provider->register($container);
        $worker = new \Crab\Engine\Process\Worker($this->loop, new \Crab\Engine\Crab('test'), 0, [], 1.0);
        $provider->start($worker, $container);
        self::assertNull($container->get(Lifecycle::class)->carrier);
        new Broadcaster(new LocalCarrier())->to('nobody')->send('text');
        self::assertSame([], $this->loop->getIdentifiers());
        self::assertSame([], $this->loop->getIdentifiers());
        $provider->stop($worker, $container);
        self::assertSame([], $this->loop->getIdentifiers());
        self::assertNull($container->get(Lifecycle::class)->carrier);
    }

    public function testALocalConsoleSenderSaysWhyItCannotReachTheServer(): void
    {
        $app = new App();
        $app->register(BroadcastProvider::class);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot reach the server from a console, queue:work or schedule:work');
        $app->container->get(BroadcasterContract::class)->to('orders')->send('hello');
    }

    public function testStartupRefusesLocalBroadcastAcrossMultipleProcesses(): void
    {
        foreach ([
            ['server' => ['workers' => 2]],
            ['server' => ['with' => ['queue']]],
            ['server' => ['with' => ['schedule']]],
        ] as $settings) {
            $app = new App(new Config($settings));
            $app->register(...App::PROVIDERS);
            $app->router->websocket('/live', static function (Socket $socket): void {
                $socket->receive();
            });
            try {
                $app->runtime('tcp://127.0.0.1:8219');
                self::fail('A process-local carrier was allowed across processes.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('Set broadcast.carrier to redis', $error->getMessage());
            }
        }
    }

    public function testJoinIsTheFirstSocketUseAndDisconnectLeavesNoMembership(): void
    {
        $app = new App(new Config(['websocket' => ['ping' => 0]]));
        $app->register(BroadcastProvider::class);
        $app->router->websocket('/orders/{id}/live', static function (Socket $socket, int $id): void {
            $socket->join("orders.{$id}");
            $socket->join("orders.{$id}");
            foreach ($socket as $message) {
                if ($message !== 'leave') {
                    continue;
                }

                $socket->leave("orders.{$id}");
                $socket->send('left');
            }
        });
        $api = $this->apis[] = new Api($app);
        $a = $api->websocket('/orders/42/live');
        $b = $api->websocket('/orders/43/live');
        $broadcast = $app->container->get(BroadcasterContract::class);
        $broadcast->to('orders.42')->send(['status' => 'shipped']);
        $a->assertReceived(['status' => 'shipped']);
        $b->assertNothingReceived();
        $a->send('leave');
        $a->assertReceived('left');
        $broadcast->to('orders.42')->send('no longer joined');
        $a->assertNothingReceived();
        $b->close();
        $a->close();
        $channels = $this->channels[] = $app->container->get(Channels::class);
        self::assertSame(0, $channels->stats()['channels']);
        self::assertSame(0, $channels->stats()['connections']);
    }

    public function testJoinWithoutTheProviderNamesWhatToRegister(): void
    {
        $app = new App(new Config(['app' => ['debug' => true], 'log' => ['path' => '/dev/null']]));
        $app->router->websocket('/live', static fn(Socket $socket) => $socket->join('orders'));
        $api = $this->apis[] = new Api($app);
        try {
            $api->websocket('/live');
            self::fail('A socket joined without a provider.');
        } catch (WebSocketRefused $error) {
            $error->response->assertStatus(500);
            self::assertStringContainsString('register BroadcastProvider', (string) $error->response->body());
        }
    }
}

/**
 * Records the carrier boundary, including a single already encoded payload.
 */
final class SpyCarrier implements Carrier
{
    public array $sent = [];
    public array $joined = [];
    public array $left = [];
    public ?\Closure $receive = null;
    public ?\Closure $gap = null;
    public ?\Throwable $failure = null;
    public ?\Throwable $leaveFailure = null;

    public function publish(array $channels, string $message): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->sent[] = [$channels, $message];
    }

    public function subscribe(string $channel, \Closure $receive, \Closure $gap): void
    {
        $this->joined[] = $channel;
        $this->receive = $receive;
        $this->gap = $gap;
    }

    public function unsubscribe(string $channel): void
    {
        $this->left[] = $channel;
        if ($this->leaveFailure !== null) {
            throw $this->leaveFailure;
        }
    }

    public function close(): void {}
}

/**
 * A nonblocking recipient lets the tests isolate scheduling and membership.
 */
final class TestRecipient implements Recipient
{
    public array $messages = [];
    public ?array $ended = null;
    public ?\Throwable $closeFailure = null;
    public ?\Throwable $broadcastFailure = null;
    public float $writableDelay = 0.0;

    public function __construct(
        public WriteResult $result = WriteResult::Accepted,
    ) {}

    public function broadcast(string $message): WriteResult
    {
        if ($this->broadcastFailure !== null) {
            throw $this->broadcastFailure;
        }
        if ($this->ended !== null) {
            return WriteResult::Closed;
        }
        if ($this->result === WriteResult::Accepted) {
            $this->messages[] = $message;
        }
        return $this->result;
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        if ($this->closeFailure !== null) {
            throw $this->closeFailure;
        }
        $this->ended = [$code, $reason];
    }

    public function broadcastTimeout(): float
    {
        return 0.02;
    }

    public function awaitBroadcastWritable(\Crab\Engine\Async\Cancellation $cancellation): bool
    {
        while ($this->result === WriteResult::Backpressured) {
            \Crab\Engine\Async\Async::sleep(EventLoop::current(), 0.001, $cancellation);
        }
        if ($this->writableDelay > 0) {
            \Crab\Engine\Async\Async::sleep(EventLoop::current(), $this->writableDelay, $cancellation);
        }
        return $this->ended === null;
    }
}

/**
 * Observes JSON encoding calls instead of depending on implementation timing.
 */
final class EncodingCounter implements \JsonSerializable
{
    public int $calls = 0;

    public function jsonSerialize(): mixed
    {
        ++$this->calls;
        return 'once';
    }
}
