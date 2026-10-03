<?php

declare(strict_types=1);

namespace Crab\Engine\Http;

use Crab\Engine\Network\Connection;
use Crab\Engine\Network\WriteResult;

/**
 * One parser and one ordered response producer own a connection until upgrade.
 */
final class HttpSession
{
    private const COMPLETE_NONE = 0;
    private const COMPLETE_RESPONSE = 1;
    private const COMPLETE_CONTINUE = 2;
    private const COMPLETE_CLOSE = 3;

    private Parser $parser;
    private bool $busy = false;
    private bool $retired = false;
    private bool $draining = false;
    private bool $peerEof = false;
    private bool $pumping = false;
    private bool $responseStarted = false;
    private int $requests = 0;
    private ?string $scheduled = null;
    private ?string $writeScheduled = null;
    private ?string $deadline = null;
    private float $deadlineAt = 0;
    private float $deadlineScheduledAt = 0;
    private string $deadlinePhase = '';
    private ?\Generator $output = null;
    private string $pending = '';
    private int $pendingOffset = 0;
    private int $completion = self::COMPLETE_NONE;
    private bool $closeAfterResponse = false;
    private ?Upgrade $upgrade = null;
    private ?Request $activeRequest = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly \Closure $handler,
        int $maxHeaderBytes = 16384,
        int $maxBodyBytes = 1048576,
        private readonly int $maxRequests = 1000,
        private readonly float $headerTimeout = 10,
        private readonly float $bodyTimeout = 30,
        private readonly float $idleTimeout = 30,
        private readonly float $responseTimeout = 30,
    ) {
        $this->parser = new Parser($maxHeaderBytes, $maxBodyBytes);
        $weak = \WeakReference::create($this);
        $connection->onClose(static function () use ($weak): void { $weak->get()?->retire(); });
        $connection->onDrain(static function () use ($weak): void { $weak->get()?->pump(); });
        $connection->onEof(function (): void {
            if (!$this->retired) {
                $this->peerEof = true;
                $this->schedule();
            }
        });
        $connection->onDrainRequest(function (): void {
            if ($this->retired) {
                return;
            }
            $this->draining = true;
            $this->connection->pauseRead();
            if (!$this->busy) {
                $this->retire();
                $this->connection->end();
            }
        });
        $connection->onData(function (Connection $connection, string $bytes): void {
            if ($this->retired) {
                return;
            }
            try {
                $this->parser->append($bytes);
            } catch (HttpException $error) {
                $this->fail($error->status);
                return;
            }
            if ($this->busy || $this->scheduled !== null) {
                if ($this->deadlinePhase === 'idle') {
                    $this->armDeadline('headers');
                }
                return;
            }
            // The first request of a read turn runs without a scheduler round
            // trip; pipelined successors keep their own deferred turns.
            $this->process();
        });
        $this->armDeadline('headers');
    }

    private function schedule(): void
    {
        if ($this->scheduled !== null || $this->retired || $this->busy) {
            return;
        }
        $this->scheduled = $this->connection->loop()->defer(function (): void {
            $this->scheduled = null;
            $this->process();
        });
    }

    private function process(): void
    {
        if ($this->retired || $this->busy || $this->connection->isClosed()) {
            return;
        }
        $this->connection->enterActivity();
        try {
            $request = $this->parser->next();
            if ($request === null) {
                if ($this->peerEof) {
                    if ($this->parser->phase() !== 'idle') {
                        $this->fail(400);
                    } else {
                        $this->retire();
                        $this->connection->end();
                    }
                    return;
                }
                $this->armDeadline($this->parser->phase());
                if ($this->parser->takeContinue()) {
                    $this->busy = true;
                    $this->connection->pauseRead();
                    $this->pending = "HTTP/1.1 100 Continue\r\n\r\n";
                    $this->pendingOffset = 0;
                    $this->completion = self::COMPLETE_CONTINUE;
                    $this->pump();
                }
                return;
            }
            $this->busy = true;
            $this->activeRequest = $request;
            $this->connection->pauseRead();
            $this->armDeadline('response');
            ++$this->requests;
            $response = ($this->handler)($request);
            if ($this->retired || $this->connection->isClosed()) {
                return;
            }
            if (is_string($response)) {
                $response = Response::text($response);
            }
            if (!$response instanceof Response && !$response instanceof Upgrade) {
                throw new \UnexpectedValueException('HTTP handlers must return Response, Upgrade, or string.');
            }
            $upgrade = $response instanceof Upgrade ? $response : null;
            if ($upgrade !== null && ($this->draining || $request->version !== '1.1'
                || !$request->hasToken('connection', 'upgrade') || $request->header('upgrade') === null)) {
                throw new \UnexpectedValueException('Upgrade requires an active connection and upgrade request.');
            }
            $close = $this->draining || $this->requests >= $this->maxRequests
                || ($this->peerEof && $this->parser->phase() === 'idle');
            $message = $upgrade === null ? ResponseEncoder::small($response, $request, $close) : null;
            if ($message === null) {
                $encoder = new ResponseEncoder($upgrade?->response ?? $response, $request, $close, $upgrade !== null);
                $close = $encoder->close;
                $message = $encoder->message();
                if ($message === null) {
                    $this->output = $encoder->bytes();
                }
            }
            if ($message !== null && $upgrade === null && !$close && $this->pending === ''
                && strlen($message) <= 8192 && strlen($message) <= $this->connection->maxWriteSize()) {
                // A complete persistent response needs no output state when
                // the transport accepts it at once.
                $result = $this->connection->tryWrite($message);
                if ($result === WriteResult::Accepted) {
                    $this->busy = false;
                    $this->activeRequest = null;
                    $request->cancellation->cancel();
                    $phase = $this->parser->phase();
                    $this->armDeadline($phase);
                    $this->connection->resumeRead();
                    if ($phase !== 'idle' || $this->peerEof) {
                        $this->schedule();
                    }
                    return;
                }
                if ($result === WriteResult::Closed) {
                    $this->retire();
                    return;
                }
            }
            $this->closeAfterResponse = $close;
            $this->upgrade = $upgrade;
            $this->completion = self::COMPLETE_RESPONSE;
            if ($message !== null) {
                $this->pending = $message;
                $this->pendingOffset = 0;
            }
            $this->pump();
        } catch (HttpException $error) {
            $this->fail($error->status);
        } catch (\Throwable) {
            $this->fail(500);
        } finally {
            $this->connection->leaveActivity();
        }
    }

    /**
     * Runs once after the ordered output of the current message is accepted.
     */
    private function complete(): void
    {
        $completion = $this->completion;
        $this->completion = self::COMPLETE_NONE;
        if ($completion === self::COMPLETE_CONTINUE) {
            $this->responseStarted = false;
            if ($this->draining) {
                $this->retire();
                $this->connection->end();
                return;
            }
            $this->busy = false;
            $this->connection->resumeRead();
            $this->schedule();
            return;
        }
        if ($completion === self::COMPLETE_CLOSE) {
            $this->retire();
            $this->connection->end();
            return;
        }
        $upgrade = $this->upgrade;
        if ($upgrade !== null) {
            $request = $this->activeRequest;
            $unread = $this->parser->takeBufferedBytes();
            $this->retire();
            $this->connection->onData(static function (): void {});
            $this->connection->onEof(static function (): void {});
            $this->connection->onDrainRequest(static function (): void {});
            // The new owner may immediately pause; never override its
            // read-interest decision after handing the connection over.
            $this->connection->resumeRead();
            ($upgrade->handler)($this->connection, $request, $unread);
            return;
        }
        if ($this->closeAfterResponse || $this->draining) {
            $this->retire();
            $this->connection->end();
            return;
        }
        $this->busy = false;
        $this->releaseRequest();
        $this->responseStarted = false;
        $phase = $this->parser->phase();
        $this->armDeadline($phase);
        $this->connection->resumeRead();
        if ($phase !== 'idle' || $this->peerEof) {
            $this->schedule();
        }
    }

    private function pump(): void
    {
        if ($this->pumping || $this->retired || $this->completion === self::COMPLETE_NONE
            || $this->connection->isClosed()) {
            return;
        }
        $this->connection->enterActivity();
        $this->pumping = true;
        try {
            for ($budget = 0; $budget < 64; ++$budget) {
                if ($this->retired || $this->connection->isClosed()) {
                    return;
                }
                if ($this->pending === '') {
                    if ($this->output === null || !$this->output->valid()) {
                        $this->output = null;
                        $this->complete();
                        return;
                    }
                    $this->pending = $this->output->current();
                    $this->pendingOffset = 0;
                }
                $chunk = substr($this->pending, $this->pendingOffset, min(8192, $this->connection->maxWriteSize()));
                if ($chunk !== '') {
                    $result = $this->connection->tryWrite($chunk);
                    if ($result === WriteResult::Backpressured) {
                        return;
                    }
                    if ($result === WriteResult::Closed) {
                        $this->retire();
                        return;
                    }
                    $this->responseStarted = true;
                    $this->pendingOffset += strlen($chunk);
                }
                if ($this->pendingOffset === strlen($this->pending)) {
                    $this->pending = '';
                    $this->output?->next();
                }
            }
            if ($this->writeScheduled === null) {
                $this->writeScheduled = $this->connection->loop()->defer(function (): void {
                    $this->writeScheduled = null;
                    $this->pump();
                });
            }
        } catch (\Throwable) {
            $this->retire();
            $this->connection->close();
        } finally {
            $this->pumping = false;
            $this->connection->leaveActivity();
        }
    }

    private function fail(int $status): void
    {
        if ($this->retired) {
            return;
        }
        if ($this->responseStarted) {
            $this->retire();
            $this->connection->close();
            return;
        }
        $this->busy = true;
        $this->draining = true;
        $this->connection->pauseRead();
        $this->parser->takeBufferedBytes();
        $request = new Request('GET', '/', '1.1', [], new Body());
        $encoder = new ResponseEncoder(Response::text(Response::reason($status) . "\n", $status), $request, true);
        $this->output = $encoder->bytes();
        $this->pending = '';
        $this->armDeadline('response');
        $this->completion = self::COMPLETE_CLOSE;
        $this->pump();
    }

    private function armDeadline(string $phase): void
    {
        if ($phase === $this->deadlinePhase && $this->deadline !== null) {
            return;
        }
        $this->deadlinePhase = $phase;
        $seconds = match ($phase) {
            'body' => $this->bodyTimeout,
            'idle' => $this->idleTimeout,
            'response' => $this->responseTimeout,
            default => $this->headerTimeout,
        };
        $this->deadlineAt = hrtime(true) / 1_000_000_000 + $seconds;
        if ($this->deadline !== null) {
            // An earlier wake-up can check the current phase without allocating
            // another timer on every successful request/response transition.
            if ($this->deadlineScheduledAt <= $this->deadlineAt) {
                return;
            }
            $this->connection->loop()->cancel($this->deadline);
        }
        $this->deadlineScheduledAt = $this->deadlineAt;
        $this->deadline = $this->connection->loop()->delay($seconds, $this->checkDeadline(...));
    }

    private function checkDeadline(): void
    {
        $this->deadline = null;
        if ($this->retired) {
            return;
        }
        $remaining = $this->deadlineAt - hrtime(true) / 1_000_000_000;
        if ($remaining > 0) {
            $this->deadlineScheduledAt = $this->deadlineAt;
            $this->deadline = $this->connection->loop()->delay($remaining, $this->checkDeadline(...));
            return;
        }
        if ($this->deadlinePhase === 'idle' || $this->deadlinePhase === 'response') {
            $this->retire();
            $this->connection->close();
        } else {
            $this->fail(408);
        }
    }

    private function retire(): void
    {
        if ($this->retired) {
            return;
        }
        $this->retired = true;
        $this->releaseRequest();
        foreach ([$this->deadline, $this->scheduled, $this->writeScheduled] as $id) {
            if ($id !== null) {
                $this->connection->loop()->cancel($id);
            }
        }
        $this->deadline = $this->scheduled = $this->writeScheduled = null;
        $this->output = null;
        $this->pending = '';
        $this->completion = self::COMPLETE_NONE;
        $this->upgrade = null;
    }

    private function releaseRequest(): void
    {
        $request = $this->activeRequest;
        $this->activeRequest = null;
        $request?->cancellation->cancel();
    }
}
