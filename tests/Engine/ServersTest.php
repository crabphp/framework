<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use stdClass;

final class ServersTest extends TestCase
{
    #[RequiresPhpExtension('pcntl')]
    public function testHttpWebSocketTcpAndUdpCoexistAndShutDownCleanly(): void
    {
        $ports = $reserved = [];
        foreach (['tcp', 'tcp', 'tcp', 'udp'] as $scheme) {
            $socket = stream_socket_server("$scheme://127.0.0.1:0", $code, $error,
                $scheme === 'udp' ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
            self::assertIsResource($socket, 'Reserve server address.');
            $this->ownStream($socket);
            $ports[] = substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
            $reserved[] = $socket;
        }
        foreach ($reserved as $socket) {
            fclose($socket);
        }
        $fixture = $this->startFixture($ports);
        $clients = [];
        try {
            self::assertSame("ready\n", fgets($fixture->stdout), 'Combined server startup deadline.');
            foreach ($ports as $index => $port) {
                $scheme = $index === 3 ? 'udp' : 'tcp';
                $socket = stream_socket_client("$scheme://127.0.0.1:$port", $code, $error, 1);
                self::assertIsResource($socket, 'Connect to server.');
                $clients[$index] = $this->ownStream($socket);
                stream_set_timeout($socket, 1);
            }
            $payload = "\0crab\xff";
            fwrite($clients[0], "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 6\r\n\r\n" . $payload
                . "GET /second HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
            fwrite($clients[1], "GET /socket HTTP/1.1\r\nHost: localhost\r\nConnection: Upgrade\r\nUpgrade: websocket\r\n"
                . "Sec-WebSocket-Version: 13\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n");
            fwrite($clients[2], $payload);
            stream_socket_sendto($clients[3], $payload);

            $responses = stream_get_contents($clients[0]);
            self::assertSame(2, substr_count($responses, 'HTTP/1.1 200 OK'));
            self::assertStringContainsString($payload, $responses);
            self::assertStringEndsWith('/second', $responses, 'HTTP pipeline order was not preserved.');

            $handshake = '';
            while (!str_ends_with($handshake, "\r\n\r\n")) {
                $line = fgets($clients[1]);
                self::assertNotFalse($line, 'WebSocket handshake deadline.');
                $handshake .= $line;
            }
            self::assertStringStartsWith('HTTP/1.1 101', $handshake);
            // Fixed masks make this fixture deterministic; real clients use fresh unpredictable masks.
            $mask = "\x01\x02\x03\x04";
            $masked = $payload ^ substr(str_repeat($mask, 2), 0, strlen($payload));
            fwrite($clients[1], "\x82\x86" . $mask . $masked);
            self::assertSame("\x82\x06" . $payload, $this->readExactly($clients[1], 8), 'WebSocket binary echo.');
            self::assertSame($payload, $this->readExactly($clients[2], strlen($payload)), 'TCP binary echo.');
            self::assertSame($payload, stream_socket_recvfrom($clients[3], 100), 'UDP binary echo.');
        } finally {
            foreach ($clients as $socket) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }
            $result = $this->finishFixture($fixture);
        }
        self::assertFalse($result['timedOut'], 'Combined server shutdown exceeded its deadline.');
        self::assertSame(0, $result['exit'], 'Combined server errors: ' . $result['errors']);
        self::assertSame('', $result['errors']);
    }

    /**
     * @param list<string> $ports
     */
    private function startFixture(array $ports): stdClass
    {
        $errorFile = tempnam(sys_get_temp_dir(), 'crab-servers-');
        self::assertNotFalse($errorFile);
        $this->deferCleanup(static function () use ($errorFile): void {
            if (is_file($errorFile)) {
                unlink($errorFile);
            }
        });
        $process = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/servers.php', ...$ports],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errorFile, 'w']], $pipes);
        self::assertIsResource($process);
        $fixture = (object) ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $errorFile, 'result' => null];
        $this->deferCleanup(fn () => $this->finishFixture($fixture));
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 2);
        return $fixture;
    }

    /**
     * @param resource $socket
     */
    private function readExactly(mixed $socket, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($socket, $length - strlen($bytes));
            self::assertNotFalse($chunk, 'Read failed.');
            self::assertNotSame('', $chunk, 'Server response deadline.');
            $bytes .= $chunk;
        }
        return $bytes;
    }

    /**
     * @param resource $stream
     * @return resource
     */
    private function ownStream(mixed $stream): mixed
    {
        $this->deferCleanup(static function () use ($stream): void {
            if (is_resource($stream)) {
                fclose($stream);
            }
        });
        return $stream;
    }

    /**
     * @return array{timedOut: bool, exit: int, errors: string}
     */
    private function finishFixture(stdClass $fixture): array
    {
        if ($fixture->result !== null) {
            return $fixture->result;
        }
        proc_terminate($fixture->process, 15);
        $deadline = microtime(true) + 2;
        do {
            $status = proc_get_status($fixture->process);
            if (!$status['running']) {
                break;
            }
            usleep(5000);
        } while (microtime(true) < $deadline);
        $timedOut = $status['running'];
        if ($timedOut) {
            if (function_exists('posix_kill')) {
                posix_kill(-$status['pid'], 9);
            }
            proc_terminate($fixture->process, 9);
        }
        fclose($fixture->stdout);
        $exit = proc_close($fixture->process);
        if ($exit === -1) {
            $exit = $status['exitcode'];
        }
        $errors = file_get_contents($fixture->stderr);
        unlink($fixture->stderr);
        return $fixture->result = ['timedOut' => $timedOut, 'exit' => $exit, 'errors' => $errors];
    }
}
