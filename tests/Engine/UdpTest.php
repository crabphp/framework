<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\EventLoop\RevoltEventLoop;
use Crab\Engine\Network\UdpEndpoint;
use Crab\Engine\Tests\Support\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class UdpTest extends TestCase
{
    public function testBinaryEmptyDatagramsBoundariesLimitsPauseAndDrain(): void
    {
        $loop = $this->newLoop();
        $messages = [];
        $endpoint = $this->endpoint(
            $loop,
            $this->guardCallback(static function (UdpEndpoint $endpoint, string $payload, string $peer) use (
                &$messages,
            ): void {
                $messages[] = $payload;
                self::assertTrue($endpoint->send($payload, $peer), 'UDP echo was rejected.');
                $endpoint->pause();
                $endpoint->resume();
            }),
            128,
        );
        $client = stream_socket_client('udp://' . $endpoint->localAddress(), $code, $error);
        self::assertIsResource($client);
        $this->deferCleanup(static function () use ($client): void {
            if (is_resource($client)) {
                fclose($client);
            }
        });
        stream_set_blocking($client, false);
        $payloads = ["\0binary\xff", '', str_repeat('a', 128)];
        $replies = [];
        $loop->onReadable($client, static function (string $watcher) use (
            $client,
            &$replies,
            $loop,
            $endpoint,
            $payloads,
        ): void {
            $peer = '';
            $payload = stream_socket_recvfrom($client, 65_536, 0, $peer);
            if ($payload === false) {
                return;
            }
            $replies[] = $payload;
            if (count($replies) === count($payloads)) {
                $endpoint->drain();
                $loop->cancel($watcher);
            }
        });
        foreach ($payloads as $payload) {
            stream_socket_sendto($client, $payload);
        }
        stream_socket_sendto($client, str_repeat('b', 129));
        $timeout = $loop->delay(1, static fn() => throw new RuntimeException('UDP test exceeded its deadline.'));
        $loop->unreference($timeout);
        try {
            $loop->run();
        } finally {
            $loop->cancel($timeout);
        }
        self::assertSame($payloads, $messages, 'Input packet boundaries or empty datagrams changed.');
        self::assertSame($payloads, $replies, 'Reply packet boundaries or empty datagrams changed.');
        self::assertSame(1, $endpoint->stats()['dropped'], 'Oversize datagrams must be discarded whole.');
        self::assertTrue($endpoint->isClosed());
        self::assertSame([], $loop->getIdentifiers());
    }

    #[DataProvider('invalidDestinations')]
    public function testRejectsInvalidNumericDestinations(string $peer): void
    {
        $endpoint = $this->endpoint($this->newLoop(), static function (): void {});
        $this->expectException(InvalidArgumentException::class);
        $endpoint->send('invalid', $peer);
    }

    public static function invalidDestinations(): iterable
    {
        yield 'bracketed IPv4' => ['[127.0.0.1]:80'];
        yield 'invalid IPv4' => ['999.999.999.999:80'];
        yield 'port overflow' => ['127.0.0.1:65536'];
        yield 'zero port' => ['127.0.0.1:0'];
        yield 'invalid IPv6' => ['[invalid]:80'];
        yield 'blocking DNS' => ['localhost:80'];
    }

    public function testCloseCallbacksFollowCleanupAndSupportLateRegistration(): void
    {
        $loop = $this->newLoop();
        $endpoint = $this->endpoint($loop, static function (): void {});
        $observed = [];
        $endpoint->onClose(static function (UdpEndpoint $endpoint) use (&$observed, $loop): void {
            $observed[] = [$endpoint->isClosed(), $loop->getIdentifiers()];
        });
        $endpoint->close();
        $endpoint->close();
        $endpoint->onClose(static function () use (&$observed): void {
            $observed[] = 'late';
        });
        self::assertSame(
            [[true, []], 'late'],
            $observed,
            'Close callbacks were repeated, lost, or ran before cleanup.',
        );
    }

    private function newLoop(): RevoltEventLoop
    {
        $loop = RevoltEventLoop::create();
        $this->deferCleanup(static function () use ($loop): void {
            foreach ($loop->getIdentifiers() as $id) {
                $loop->cancel($id);
            }
        });
        return $loop;
    }

    private function endpoint(RevoltEventLoop $loop, callable $handler, int $maxDatagramSize = 65_507): UdpEndpoint
    {
        $socket = UdpEndpoint::bind('udp://127.0.0.1:0');
        $this->deferCleanup(static function () use ($socket): void {
            if (is_resource($socket)) {
                fclose($socket);
            }
        });
        $endpoint = new UdpEndpoint($loop, $socket, $handler, maxDatagramSize: $maxDatagramSize);
        $this->deferCleanup($endpoint->close(...));
        return $endpoint;
    }
}
