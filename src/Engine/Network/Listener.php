<?php

declare(strict_types=1);

namespace Crab\Engine\Network;

use Closure;
use Crab\Engine\EventLoop;
use Crab\Engine\FailureReporter;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class Listener
{
    private ?string $watcher = null;
    private bool $closed = false;
    private bool $paused = false;
    private readonly Closure $handler;

    /**
     * @param resource $stream Ownership transfers to this listener.
     */
    public function __construct(
        private readonly EventLoop $loop,
        private mixed $stream,
        callable $onConnection,
    ) {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('Listener requires an open server stream.');
        }
        if (!stream_set_blocking($stream, false)) {
            throw new InvalidArgumentException('Listener requires a nonblocking server stream.');
        }
        $this->handler = Closure::fromCallable($onConnection);
    }

    /**
     * @return resource
     */
    public static function bind(string $address, array $contextOptions = []): mixed
    {
        if (!str_starts_with($address, 'tcp://')) {
            throw new InvalidArgumentException(
                'TCP listeners require tcp:// addresses; TLS is not supported by this transport.',
            );
        }
        $contextOptions['socket'] ??= [];
        $contextOptions['socket'] += ['backlog' => 1024, 'tcp_nodelay' => true];
        $stream = @stream_socket_server(
            $address,
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            stream_context_create($contextOptions),
        );
        if ($stream === false) {
            throw new RuntimeException("Could not bind {$address}: {$error}", $errno);
        }
        if (!stream_set_blocking($stream, false)) {
            fclose($stream);
            throw new RuntimeException("Could not make listener {$address} nonblocking.");
        }
        return $stream;
    }

    /**
     * A listening socket this process was started with, by its descriptor
     * number, as the server it takes over from passed it on.
     *
     * @return resource
     */
    public static function inherited(int $descriptor): mixed
    {
        $stream = @fopen("php://fd/{$descriptor}", 'r+');
        if (
            $stream === false
            || !str_contains((string) (stream_get_meta_data($stream)['stream_type'] ?? ''), 'socket')
        ) {
            throw new RuntimeException("Descriptor {$descriptor} is not a listening socket passed to this process.");
        }
        if (!stream_set_blocking($stream, false)) {
            fclose($stream);
            throw new RuntimeException("Could not make the inherited listener {$descriptor} nonblocking.");
        }
        return $stream;
    }

    public function attach(): void
    {
        if ($this->closed || $this->watcher !== null) {
            return;
        }
        $this->watcher = $this->loop->onReadable($this->stream, $this->accept(...));
        if ($this->paused) {
            $this->loop->disable($this->watcher);
        }
    }

    public function pause(): void
    {
        $this->paused = true;
        if ($this->watcher !== null) {
            $this->loop->disable($this->watcher);
        }
    }

    public function resume(): void
    {
        if ($this->closed) {
            return;
        }
        $this->paused = false;
        if ($this->watcher === null) {
            $this->attach();
        } else {
            $this->loop->enable($this->watcher);
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->watcher !== null) {
            $this->loop->cancel($this->watcher);
            $this->watcher = null;
        }
        fclose($this->stream);
        $this->stream = null;
    }

    private function accept(): void
    {
        for ($accepted = 0; $accepted < 64 && !$this->closed && !$this->paused; $accepted++) {
            $stream = @stream_socket_accept($this->stream, 0, $peer);
            if ($stream === false) {
                return;
            }
            try {
                $connection = new Connection($this->loop, $stream, $peer);
            } catch (Throwable $error) {
                fclose($stream);
                throw $error;
            }
            try {
                ($this->handler)($connection);
            } catch (Throwable $error) {
                $connection->close();
                FailureReporter::report('Accept callback failed', $error);
            }
        }
    }
}
