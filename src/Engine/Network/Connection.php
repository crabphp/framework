<?php

declare(strict_types=1);

namespace Crab\Engine\Network;

use Closure;
use Crab\Engine\EventLoop;
use Crab\Engine\FailureReporter;
use InvalidArgumentException;
use LengthException;
use LogicException;
use Throwable;

/**
 * A nonblocking byte stream. Low-level callbacks must not suspend.
 */
final class Connection
{
    private const READ_CHUNK_SIZE = 65_536;
    private const IO_BUDGET = 262_144;
    private const MAX_QUEUE_ENTRIES = 4096;

    private mixed $stream;
    private bool $encrypted = false;
    private bool $handshaking = false;
    private ?string $tlsReadWatcher = null;
    private ?string $tlsTimer = null;
    private ?string $tlsRetry = null;
    private ?string $readContinuation = null;
    private ?Closure $tlsReady = null;
    private int $tlsMethod = 0;
    private ?string $readWatcher = null;
    private ?string $writeWatcher = null;
    private ?string $closeTimer = null;
    private ?float $closeDeadline = null;
    private ?string $observedWriteWatcher = null;
    private ?Closure $writeProgressHandler = null;
    private ?string $drainNotification = null;
    private ?Closure $dataHandler = null;
    private ?Closure $eofHandler = null;
    private ?Closure $drainHandler = null;
    private ?Closure $pendingWork = null;
    private ?Closure $errorHandler = null;
    /**
     * @var list<Closure>
     */
    private array $closeHandlers = [];
    /**
     * @var list<Closure>
     */
    private array $settledHandlers = [];
    private int $activities = 0;
    private bool $closeNotified = false;
    private bool $settled = false;
    /**
     * @var list<Closure>
     */
    private array $drainHandlers = [];
    /**
     * @var array<int, string>
     */
    private array $output = [];
    private int $head = 0;
    private int $tail = 0;
    private int $headOffset = 0;
    private int $buffered = 0;
    private bool $closed = false;
    private bool $readPaused = false;
    private bool $reading = false;
    private bool $readWatching = false;
    private bool $readEnded = false;
    private bool $writeEnding = false;
    private bool $writeEnded = false;
    private bool $ending = false;
    private bool $draining = false;
    private bool $writeRejected = false;

    /**
     * @param resource $stream Ownership transfers to this connection. Outside streams must be opened with a private context.
     */
    public function __construct(
        private readonly EventLoop $driver,
        mixed $stream,
        public readonly string $remoteAddress = '',
        private readonly int $maxWriteBufferSize = 1_048_576,
        private readonly float $closeTimeout = 5.0,
        private readonly int $readSize = self::READ_CHUNK_SIZE,
    ) {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('Connection requires an open stream.');
        }
        if ($readSize < 1 || $readSize > self::READ_CHUNK_SIZE) {
            throw new InvalidArgumentException('Set the read size between 1 and 65,536 bytes.');
        }
        if ($maxWriteBufferSize < 1 || !is_finite($closeTimeout) || $closeTimeout <= 0) {
            throw new InvalidArgumentException('Write capacity and close timeout must be positive.');
        }
        if (!stream_set_blocking($stream, false)) {
            throw new InvalidArgumentException('Connection requires a nonblocking stream.');
        }
        stream_set_read_buffer($stream, 0);
        stream_set_write_buffer($stream, 0);
        stream_set_chunk_size($stream, self::READ_CHUNK_SIZE);
        $this->stream = $stream;
        $this->encrypted = isset(stream_get_meta_data($stream)['crypto']);
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    /**
     * Upgrade an idle plaintext transport. The owner must pass any bytes it
     * already read after STARTTLS in $unread; those bytes fail the connection.
     * No application I/O runs until onReady. Failure closes the connection.
     * Client verification defaults to enabled; SMTP callers may disable both
     * verify_peer and verify_peer_name explicitly for opportunistic encryption.
     *
     * @param callable(self): void $onReady Must not suspend.
     * @param array<string, mixed> $options PHP SSL context options, including certificate paths on servers.
     */
    public function startTls(
        callable $onReady,
        array $options = [],
        bool $server = true,
        float $timeout = 10.0,
        string $unread = '',
    ): void {
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new InvalidArgumentException('Set the TLS timeout to a finite number of seconds greater than zero.');
        }
        if ($this->closed || $this->encrypted || $this->handshaking || $this->writeEnding || $this->readEnded) {
            throw new LogicException('TLS requires an open plaintext connection.');
        }
        if ($this->buffered !== 0) {
            throw new LogicException('Flush all queued plaintext output before starting TLS.');
        }
        if ($unread !== '') {
            $this->close();
            return;
        }
        if ($server) {
            try {
                new TlsCertificate($options)->check();
            } catch (Throwable $error) {
                $this->reportFailure('TLS local configuration failed', $error);
                $this->close();
                return;
            }
        }
        $options += [
            'verify_peer' => !$server,
            'verify_peer_name' => !$server,
            'allow_self_signed' => false,
            'disable_compression' => true,
            'SNI_enabled' => true,
            'ciphers' => 'DEFAULT',
        ];
        // PHP cannot detach or unset an existing stream context. Engine-created
        // streams have private contexts; outside streams must provide one too.
        foreach (stream_context_get_options($this->stream)['ssl'] ?? [] as $name => $value) {
            if (!array_key_exists($name, $options) || $options[$name] !== $value) {
                throw new LogicException(
                    'TLS found SSL options already on the stream; create the stream with its own empty context and pass TLS options to startTls().',
                );
            }
        }
        foreach ($options as $name => $value) {
            stream_context_set_option($this->stream, 'ssl', $name, $value);
        }
        $this->handshaking = true;
        $this->tlsReady = Closure::fromCallable($onReady);
        $this->tlsMethod = $server ? STREAM_CRYPTO_METHOD_TLS_SERVER : STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if ($this->readWatcher !== null) {
            $this->driver->disable($this->readWatcher);
            $this->readWatching = false;
        }
        $this->tlsReadWatcher = $this->driver->onReadable($this->stream, $this->handshake(...));
        $this->tlsTimer = $this->driver->delay($timeout, $this->close(...));
        $this->handshake();
    }

    private function handshake(): void
    {
        if ($this->closed || !$this->handshaking) {
            return;
        }
        while (openssl_error_string() !== false) {
            // This step must not attribute another operation's errors to TLS.
        }
        error_clear_last();
        $result = @stream_socket_enable_crypto($this->stream, true, $this->tlsMethod);
        $diagnostics = [];
        if ($result === false && ($warning = error_get_last()['message'] ?? '') !== '') {
            $diagnostics[] = $warning;
        }
        while (($reason = openssl_error_string()) !== false) {
            $diagnostics[] = $reason;
        }
        if ($result === false) {
            $message = implode('; ', $diagnostics);
            if (
                preg_match(
                    '/(?:local cert|private key|no cipher match|no ciphers available|no protocols available|SSL_CTX)/i',
                    $message,
                ) === 1
            ) {
                $this->reportFailure('TLS local handshake setup failed', new \RuntimeException($message));
            }
            $this->close();
            return;
        }
        if ($result === 0) {
            // PHP does not expose OpenSSL's WANT_READ / WANT_WRITE direction.
            // Readiness handles input; a bounded retry handles WANT_WRITE without
            // a permanently writable watcher spinning for a silent peer.
            if ($this->tlsRetry === null) {
                $this->tlsRetry = $this->driver->delay(0.01, function (): void {
                    $this->tlsRetry = null;
                    $this->handshake();
                });
            }
            return;
        }
        foreach (['tlsReadWatcher', 'tlsTimer', 'tlsRetry'] as $property) {
            if ($this->{$property} === null) {
                continue;
            }
            $this->driver->cancel($this->{$property});
            $this->{$property} = null;
        }
        $this->encrypted = true;
        $this->handshaking = false;
        $ready = $this->tlsReady;
        $this->tlsReady = null;
        $this->invoke($ready);
        $this->updateReadWatcher();
    }

    public function loop(): EventLoop
    {
        return $this->driver;
    }

    public function maxWriteSize(): int
    {
        return $this->maxWriteBufferSize;
    }

    /**
     * @param callable(self, string): void $handler Replaces the current reader.
     */
    public function onData(callable $handler): self
    {
        $this->dataHandler = Closure::fromCallable($handler);
        $this->updateReadWatcher();
        return $this;
    }

    /**
     * @param callable(self): void $handler Runs after resource cleanup.
     */
    public function onClose(callable $handler): self
    {
        $handler = Closure::fromCallable($handler);
        if ($this->closed) {
            $this->invoke($handler);
        } else {
            $this->closeHandlers[] = $handler;
        }
        return $this;
    }

    /**
     * Keep service work owned until its callback and asynchronous cleanup return.
     * The socket still closes immediately; the returned release is idempotent.
     *
     * @return Closure(): void
     */
    public function beginActivity(): Closure
    {
        if ($this->closed) {
            throw new LogicException('Cannot begin service work on a closed connection.');
        }
        ++$this->activities;
        $released = false;
        return function () use (&$released): void {
            if ($released) {
                return;
            }
            $released = true;
            --$this->activities;
            $this->settleIfReady();
        };
    }

    /**
     * Allocation-free beginActivity() for protocol owners. Returns false, and
     * opens no scope, on a closed connection. Every true result must be paired
     * with exactly one leaveActivity(), normally in a finally block.
     *
     * @internal
     */
    public function enterActivity(): bool
    {
        if ($this->closed) {
            return false;
        }
        ++$this->activities;
        return true;
    }

    /**
     * @internal Releases one enterActivity() scope; valid after the socket closes.
     */
    public function leaveActivity(): void
    {
        if (--$this->activities === 0 && $this->closeNotified) {
            $this->settleIfReady();
        }
    }

    /**
     * @param callable(self): void $handler Runs after close and all service activities exit.
     */
    public function onSettled(callable $handler): self
    {
        $handler = Closure::fromCallable($handler);
        if ($this->settled) {
            $this->invoke($handler);
        } else {
            $this->settledHandlers[] = $handler;
        }
        return $this;
    }

    /**
     * @param callable(self): void $handler Capacity is available, but is not reserved.
     */
    public function onDrain(callable $handler): self
    {
        $this->drainHandlers[] = Closure::fromCallable($handler);
        return $this;
    }

    /**
     * Observe the current output queue without changing the ordinary I/O path.
     * Reapply after enqueueing more output; null restores the ordinary writer.
     *
     * @param null|callable(self): void $handler Low-level callback; must not suspend.
     * @internal
     */
    public function onWriteProgress(?callable $handler): self
    {
        if ($this->closed) {
            return $this;
        }
        $this->writeProgressHandler = $handler === null ? null : Closure::fromCallable($handler);
        $observe = $handler !== null;
        if ($this->writeWatcher !== null && $observe !== ($this->observedWriteWatcher === $this->writeWatcher)) {
            $this->driver->cancel($this->writeWatcher);
            $this->writeWatcher = $this->driver->onWritable(
                $this->stream,
                $handler === null ? $this->flush(...) : $this->flushObserved(...),
            );
        }
        $this->observedWriteWatcher = $handler === null ? null : $this->writeWatcher;
        return $this;
    }

    /**
     * @param callable(self): void $handler May finish a reply after read EOF.
     */
    public function onEof(callable $handler): self
    {
        $this->eofHandler = Closure::fromCallable($handler);
        return $this;
    }

    /**
     * @param callable(self): void $handler Protocol-specific graceful shutdown.
     * @param (callable(self): bool)|null $pendingWork Whether the protocol is reading, handling or answering a request; null counts any open connection.
     */
    public function onDrainRequest(callable $handler, ?callable $pendingWork = null): self
    {
        $this->drainHandler = Closure::fromCallable($handler);
        $this->pendingWork = $pendingWork === null ? null : Closure::fromCallable($pendingWork);
        return $this;
    }

    /**
     * Asks the protocol owner what would be cut by closing this connection.
     * An unclassified open stream is conservatively treated as work.
     */
    public function hasPendingWork(): bool
    {
        return !$this->closed && ($this->pendingWork === null || ($this->pendingWork)($this));
    }

    /**
     * Monotonic cutoff owned by the transport during worker drain.
     *
     * @internal
     */
    public function drainDeadline(): ?float
    {
        return $this->draining ? $this->closeDeadline : null;
    }

    /**
     * @param callable(Throwable, self): void $handler Reports application callback failures.
     */
    public function onError(callable $handler): self
    {
        $this->errorHandler = Closure::fromCallable($handler);
        return $this;
    }

    /**
     * Gives the protocol time to finish. A worker supplies its own allowance
     * so the transport's ordinary close timeout cannot shorten that drain.
     */
    public function drain(?float $timeout = null): void
    {
        if ($timeout !== null && (!is_finite($timeout) || $timeout <= 0)) {
            throw new InvalidArgumentException(
                'Set the connection drain timeout to a finite number of seconds greater than zero.',
            );
        }
        if ($this->closed || $this->draining) {
            return;
        }
        $this->draining = true;
        if ($timeout !== null && $this->closeTimer !== null) {
            $this->driver->cancel($this->closeTimer);
            $this->closeTimer = null;
        }
        $this->armCloseDeadline($timeout);
        if ($this->drainHandler !== null) {
            $this->invoke($this->drainHandler);
        } else {
            $this->end();
        }
    }

    /**
     * Accepted means locally accepted; temporary rejection consumes no bytes.
     */
    public function tryWrite(string $bytes): WriteResult
    {
        if ($this->closed || $this->writeEnding || $this->handshaking) {
            return WriteResult::Closed;
        }
        $length = strlen($bytes);
        if ($length > $this->maxWriteBufferSize) {
            throw new LengthException('Write exceeds the connection write capacity.');
        }
        if ($length === 0) {
            return WriteResult::Accepted;
        }
        if (
            $length > ($this->maxWriteBufferSize - $this->buffered)
            || ($this->tail - $this->head) >= self::MAX_QUEUE_ENTRIES
        ) {
            $this->writeRejected = true;
            return WriteResult::Backpressured;
        }

        if ($this->buffered === 0) {
            $written = @fwrite($this->stream, $bytes);
            if ($written === false) {
                $this->close();
                return WriteResult::Closed;
            }
            if ($written === $length) {
                return WriteResult::Accepted;
            }
            if ($written > 0) {
                $bytes = substr($bytes, $written);
                $length -= $written;
            }
        }

        $this->output[$this->tail++] = $bytes;
        $this->buffered += $length;
        if ($this->writeWatcher === null) {
            $this->writeWatcher = $this->driver->onWritable($this->stream, $this->flush(...));
        }
        return WriteResult::Accepted;
    }

    public function pauseRead(): void
    {
        $this->readPaused = true;
        $this->updateReadWatcher();
    }

    public function resumeRead(): void
    {
        $this->readPaused = false;
        $this->updateReadWatcher();
        if ($this->encrypted && !$this->closed && $this->readContinuation === null) {
            $this->readContinuation = $this->driver->defer(function (): void {
                $this->readContinuation = null;
                $this->read();
            });
        }
    }

    /**
     * Flush accepted output, then close the entire connection.
     */
    public function end(): void
    {
        if ($this->closed || $this->ending) {
            return;
        }
        $this->ending = true;
        $this->writeEnding = true;
        $this->pauseRead();
        if ($this->buffered === 0) {
            $this->close();
        } else {
            $this->armCloseDeadline();
        }
    }

    /**
     * On plaintext, flush then send FIN while continuing to receive.
     * On TLS, flush and close fully with close_notify; TLS has no half-close.
     */
    public function shutdownWrite(): void
    {
        if ($this->encrypted || $this->handshaking) {
            // PHP streams cannot send a bounded TLS close_notify while keeping
            // the read direction open. Full close flushes and sends it safely.
            $this->end();
            return;
        }
        if ($this->closed || $this->writeEnding || $this->handshaking) {
            return;
        }
        $this->writeEnding = true;
        $this->armCloseDeadline();
        if ($this->buffered === 0) {
            $this->finishWrite();
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ([
            'readWatcher',
            'writeWatcher',
            'closeTimer',
            'drainNotification',
            'tlsReadWatcher',
            'tlsTimer',
            'tlsRetry',
            'readContinuation',
        ] as $property) {
            if ($this->{$property} === null) {
                continue;
            }

            $this->driver->cancel($this->{$property});
            $this->{$property} = null;
        }
        fclose($this->stream);
        $this->stream = null;
        $this->closeDeadline = null;
        $this->tlsReady = null;
        $this->observedWriteWatcher = null;
        $this->writeProgressHandler = null;
        $this->output = [];
        $this->buffered = 0;
        $handlers = $this->closeHandlers;
        $this->closeHandlers = $this->drainHandlers = [];
        $this->dataHandler = $this->eofHandler = $this->drainHandler = $this->pendingWork = null;
        foreach ($handlers as $handler) {
            $this->invoke($handler);
        }
        $this->closeNotified = true;
        $this->settleIfReady();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function bufferedBytes(): int
    {
        return $this->buffered;
    }

    private function settleIfReady(): void
    {
        if (!$this->closeNotified || $this->activities !== 0 || $this->settled) {
            return;
        }
        $this->settled = true;
        $handlers = $this->settledHandlers;
        $this->settledHandlers = [];
        foreach ($handlers as $handler) {
            $this->invoke($handler);
        }
        $this->errorHandler = null;
    }

    private function updateReadWatcher(): void
    {
        // A read turn reconciles interest once when it ends, so a consumer that
        // pauses and resumes within one turn costs no driver registration work.
        if ($this->closed || $this->readEnded || $this->reading) {
            return;
        }
        if ($this->readPaused || $this->handshaking || $this->dataHandler === null) {
            if ($this->readWatching) {
                $this->readWatching = false;
                $this->driver->disable($this->readWatcher);
            }
            return;
        }
        if ($this->readWatcher === null) {
            $this->readWatcher = $this->driver->onReadable($this->stream, $this->read(...));
            $this->readWatching = true;
        } elseif (!$this->readWatching) {
            $this->readWatching = true;
            $this->driver->enable($this->readWatcher);
        }
    }

    private function read(): void
    {
        if ($this->closed || $this->readPaused || $this->handshaking || $this->reading || $this->readEnded) {
            // Readiness during a suspended read turn must not spin the loop.
            if ($this->readWatching && $this->readWatcher !== null) {
                $this->readWatching = false;
                $this->driver->disable($this->readWatcher);
            }
            return;
        }
        $this->reading = true;
        try {
            $remaining = self::IO_BUDGET;
            $readSize = $this->readSize;
            do {
                $bytes = @fread($this->stream, $readSize);
                if ($bytes === false) {
                    $this->close();
                    return;
                }
                if ($bytes === '') {
                    if (feof($this->stream)) {
                        $this->receiveEof();
                    }
                    return;
                }
                $received = strlen($bytes);
                try {
                    ($this->dataHandler)($this, $bytes);
                } catch (Throwable $error) {
                    $this->report($error);
                }
                if ($received < $readSize && !$this->encrypted) {
                    // Readiness will report further bytes or EOF on a later turn.
                    return;
                }
                $remaining -= $received;
                if ($remaining < $readSize) {
                    $readSize = $remaining;
                }
            } while (!$this->closed && !$this->readPaused && !$this->handshaking && $remaining > 0);
            if (
                $this->encrypted
                && !$this->closed
                && !$this->readPaused
                && !$this->handshaking
                && $this->readContinuation === null
            ) {
                // OpenSSL can have plaintext ready while select sees no socket
                // bytes. Probe once more next turn after exhausting the budget.
                $this->readContinuation = $this->driver->defer(function (): void {
                    $this->readContinuation = null;
                    $this->read();
                });
            }
        } finally {
            $this->reading = false;
            // An untouched, still-watched connection has nothing to reconcile.
            if (
                !$this->readWatching
                || $this->readPaused
                || $this->handshaking
                || $this->closed
                || $this->readEnded
                || $this->dataHandler === null
            ) {
                $this->updateReadWatcher();
            }
        }
    }

    private function flush(): void
    {
        if ($this->closed || $this->buffered === 0) {
            return;
        }
        $before = $this->buffered;
        $remaining = self::IO_BUDGET;
        $entries = 0;
        while ($this->buffered > 0 && $remaining > 0 && $entries++ < 64) {
            $chunk = $this->output[$this->head];
            if ($this->headOffset !== 0) {
                $chunk = substr($chunk, $this->headOffset);
            }
            $written = @fwrite($this->stream, $chunk, min(strlen($chunk), $remaining));
            if ($written === false) {
                $this->close();
                return;
            }
            if ($written === 0) {
                break;
            }
            $remaining -= $written;
            $this->buffered -= $written;
            $this->headOffset += $written;
            if ($this->headOffset === strlen($this->output[$this->head])) {
                unset($this->output[$this->head++]);
                $this->headOffset = 0;
            }
        }
        if ($this->buffered === 0) {
            $this->driver->cancel($this->writeWatcher);
            $this->writeWatcher = null;
            $this->output = [];
            $this->head = $this->tail = 0;
            if ($this->ending) {
                $this->close();
                return;
            }
            if ($this->writeEnding) {
                $this->finishWrite();
            }
        }
        if (
            !$this->closed
            && $this->buffered < $before
            && (
                $this->buffered === 0
                || $this->writeRejected
                || $before > intdiv($this->maxWriteBufferSize, 2)
                && $this->buffered <= intdiv($this->maxWriteBufferSize, 2)
            )
        ) {
            $this->notifyDrain();
        }
    }

    private function flushObserved(): void
    {
        $before = $this->buffered;
        $this->flush();
        if (!$this->closed && $this->buffered < $before && $this->writeProgressHandler !== null) {
            $this->invoke($this->writeProgressHandler);
        }
    }

    private function notifyDrain(): void
    {
        $this->writeRejected = false;
        if ($this->drainNotification !== null || $this->drainHandlers === []) {
            return;
        }
        // Deliver after queue mutations, preventing recursive producer writes.
        $this->drainNotification = $this->driver->defer(function (): void {
            $this->drainNotification = null;
            foreach ($this->drainHandlers as $handler) {
                if ($this->closed) {
                    break;
                }
                $this->invoke($handler);
            }
        });
    }

    private function receiveEof(): void
    {
        $this->readEnded = true;
        $this->driver->cancel($this->readWatcher);
        $this->readWatcher = null;
        $this->readWatching = false;
        $this->armCloseDeadline();
        if ($this->eofHandler !== null) {
            $this->invoke($this->eofHandler);
        } else {
            $this->end();
        }
        if ($this->writeEnded && !$this->closed) {
            $this->close();
        }
    }

    private function finishWrite(): void
    {
        $this->writeEnded = true;
        if ($this->readEnded || !@stream_socket_shutdown($this->stream, STREAM_SHUT_WR)) {
            $this->close();
        }
    }

    private function armCloseDeadline(?float $timeout = null): void
    {
        if ($this->closeTimer === null) {
            $timeout ??= $this->closeTimeout;
            $this->closeDeadline = (hrtime(true) / 1_000_000_000) + $timeout;
            $this->closeTimer = $this->driver->delay($timeout, function (): void {
                $this->closeTimer = null;
                $this->close();
            });
        }
    }

    /**
     * Reports a failure that the connection's protocol owner caught, to the
     * connection's own error handler when it has one. It does not close
     * the connection; the owner decides what happens to it.
     *
     * @internal
     */
    public function reportFailure(string $what, Throwable $error): void
    {
        $reporter = $this->errorHandler;
        if ($reporter !== null) {
            try {
                $reporter($error, $this);
                return;
            } catch (Throwable $reportingError) {
                FailureReporter::report('Connection error handler failed', $reportingError);
            }
        }
        FailureReporter::report($what, $error);
    }

    private function invoke(Closure $handler): void
    {
        try {
            $handler($this);
        } catch (Throwable $error) {
            $this->report($error);
        }
    }

    /**
     * Closes the connection after a failed application callback and reports
     * the failure.
     */
    private function report(Throwable $error): void
    {
        $reporter = $this->errorHandler;
        $this->close();
        try {
            if ($reporter !== null) {
                $reporter($error, $this);
                return;
            }
        } catch (Throwable $reportingError) {
            FailureReporter::report('Connection error handler failed', $reportingError);
        }
        FailureReporter::report('Connection callback failed', $error);
    }
}
