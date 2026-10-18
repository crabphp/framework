<?php

declare(strict_types=1);

namespace Crab\Tests\Database\Mysql;

use Crab\Database\Mysql\Codec;
use Crab\Database\Mysql\Packets;
use Crab\Engine\EventLoop;
use Crab\Engine\Process\Worker;
use Crab\Testing\WorkerHarness;

/**
 * A local fake MySQL peer with real nonblocking reads and packet sequences.
 * It owns only ephemeral listeners and sockets created by the current test.
 */
final class ScriptedServer
{
    public int $sequence = 0;

    public function __construct(
        private mixed $socket,
        private readonly EventLoop $loop,
    ) {
        stream_set_blocking($socket, false);
        stream_set_read_buffer($socket, 0);
    }

    public function send(string $payload): void
    {
        foreach (Packets::encode($payload, $this->sequence) as $packet) {
            $offset = 0;
            while ($offset < strlen($packet)) {
                $written = @fwrite($this->socket, substr($packet, $offset));
                if ($written === false || $written === 0 && feof($this->socket)) {
                    throw new \RuntimeException('The fake MySQL client closed while its peer was sending.');
                }
                if ($written === 0) {
                    $this->wait(true);
                }
                $offset += $written;
            }
        }
    }

    public function receive(): string
    {
        return Packets::read($this->read(...), $this->sequence);
    }

    public function read(int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($this->socket, $length - strlen($bytes));
            if ($chunk === '' && feof($this->socket)) {
                throw new \RuntimeException('The fake MySQL client closed its socket.');
            }
            if ($chunk === '') {
                $this->wait(false);
            }
            $bytes .= $chunk;
        }
        return $bytes;
    }

    public function eof(): bool
    {
        while (true) {
            $bytes = fread($this->socket, 1);
            if ($bytes !== '') {
                return false;
            }
            if (feof($this->socket)) {
                return true;
            }
            $this->wait(false);
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    public function encrypt(string $certificate): void
    {
        stream_context_set_options($this->socket, ['ssl' => ['local_cert' => $certificate, 'verify_peer' => false]]);
        while (($done = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) !== true) {
            if ($done === false) {
                throw new \RuntimeException('The fake MySQL server could not establish TLS.');
            }
            $this->wait(false);
        }
    }

    private function wait(bool $writable): void
    {
        $suspension = $this->loop->getSuspension();
        $watcher = $writable
            ? $this->loop->onWritable($this->socket, static fn() => $suspension->resume())
            : $this->loop->onReadable($this->socket, static fn() => $suspension->resume());
        try {
            $suspension->suspend();
        } finally {
            $this->loop->cancel($watcher);
        }
    }

    public function greeting(string $plugin, int $extraFlags = 0): void
    {
        $flags = 0x28_820d | 0x6_0000 | $extraFlags;
        $this->send(
            "\x0a8.0.0-test\0"
            . pack('V', 123)
            . '12345678'
            . "\0"
            . pack('v', $flags & 65_535)
            . "\x2d\x02\0"
            . pack('v', $flags >> 16)
            . "\x15"
            . str_repeat("\0", 10)
            . "abcdefgh1234\0"
            . $plugin
            . "\0",
        );
    }

    public function ok(): void
    {
        $this->send("\0\0\0\x02\0\0\0");
    }

    public function prepare(int $id = 1, int $takes = 0, int $columns = 0): void
    {
        $this->sequence = 0;
        $this->receive();
        $this->send("\0" . pack('VvvCv', $id, $columns, $takes, 0, 0));
        foreach ([$takes, $columns] as $count) {
            for ($i = 0; $i < $count; ++$i) {
                $this->send(self::column('n'));
            }
            if ($count > 0) {
                $this->send("\xfe\0\0\x02\0");
            }
        }
    }

    public static function column(string $name, int $type = 3): string
    {
        return (
            Codec::string('def')
            . str_repeat("\0", 3)
            . Codec::string($name)
            . Codec::string($name)
            . "\x0c"
            . pack('vVCvC', 45, 11, $type, 0, 0)
            . "\0\0"
        );
    }

    /**
     * Runs client and server on a worker loop, joining both before cleanup.
     */
    public static function run(\Closure $script, \Closure $client, bool $unix = false, int $connections = 1): mixed
    {
        $path = sys_get_temp_dir() . '/crab-mysql-' . bin2hex(random_bytes(5)) . '.sock';
        $listener = stream_socket_server($unix ? 'unix://' . $path : 'tcp://127.0.0.1:0', $number, $reason);
        if ($listener === false) {
            throw new \RuntimeException('Cannot create the fake MySQL socket: ' . $reason);
        }
        stream_set_blocking($listener, false);
        $dsn = $unix
            ? 'mysql:unix_socket=' . $path . ';dbname=crab_test'
            : 'mysql:host='
            . str_replace(':', ';port=', stream_socket_get_name($listener, false))
            . ';dbname=crab_test';
        try {
            return WorkerHarness::run([], static function (Worker $worker) use (
                $listener,
                $dsn,
                $script,
                $client,
                $connections,
            ): mixed {
                $servers = [];
                $watcher = $worker->loop->onReadable($listener, static function (string $watcher) use (
                    $listener,
                    $worker,
                    $script,
                    $connections,
                    &$servers,
                ): void {
                    $socket = stream_socket_accept($listener, 0);
                    $index = count($servers);
                    if (($index + 1) >= $connections) {
                        $worker->loop->cancel($watcher);
                    }
                    $peer = new self($socket, $worker->loop);
                    $servers[] = $worker->spawn(static function () use ($script, $peer, $index): void {
                        try {
                            $script($peer, $index);
                        } finally {
                            $peer->close();
                        }
                    });
                });
                try {
                    return $client($dsn, $worker);
                } finally {
                    $worker->loop->cancel($watcher);
                    foreach ($servers as $server) {
                        $server->await();
                    }
                }
            });
        } finally {
            fclose($listener);
            if ($unix) {
                @unlink($path);
            }
        }
    }

    /**
     * A blocking PDO client needs its scripted peer in an owned child process.
     * Only that child's PID is waited for during fixture cleanup.
     */
    public static function runPdo(\Closure $script, \Closure $client): mixed
    {
        if (!function_exists('pcntl_fork')) {
            \PHPUnit\Framework\TestCase::markTestSkipped('Scripted PDO peers need pcntl.');
        }
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $dsn =
            'mysql:host=' . str_replace(':', ';port=', stream_socket_get_name($listener, false)) . ';dbname=crab_test';
        $log = tempnam(sys_get_temp_dir(), 'crab-pdo-peer-');
        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($listener);
            unlink($log);
            throw new \RuntimeException('Cannot fork the scripted PDO peer.');
        }
        if ($pid === 0) {
            pcntl_alarm(10);
            try {
                $socket = stream_socket_accept($listener, 5);
                if ($socket === false) {
                    throw new \RuntimeException('PDO did not connect to its scripted peer.');
                }
                WorkerHarness::run([], static function (Worker $worker) use ($socket, $script): void {
                    $peer = new self($socket, $worker->loop);
                    try {
                        $script($peer);
                    } finally {
                        $peer->close();
                    }
                });
                exit(0);
            } catch (\Throwable $error) {
                file_put_contents($log, $error->getMessage());
                exit(1);
            }
        }
        fclose($listener);
        try {
            return $client($dsn);
        } finally {
            pcntl_waitpid($pid, $status);
            $error = (string) file_get_contents($log);
            unlink($log);
            \PHPUnit\Framework\Assert::assertSame(
                0,
                pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1,
                $error,
            );
        }
    }
}
