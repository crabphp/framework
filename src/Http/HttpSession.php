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
    private ?\Closure $afterOutput = null;
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
                if ($this->deadlinePhase === 'idle') {
                    $this->armDeadline('headers');
                }
                $this->parser->append($bytes);
                $this->schedule();
            } catch (HttpException $error) {
                $this->fail($error->status);
            }
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
        $releaseActivity = $this->connection->beginActivity();
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
                    $this->output = (static function (): \Generator { yield "HTTP/1.1 100 Continue\r\n\r\n"; })();
                    $this->afterOutput = function (): void {
                        $this->responseStarted = false;
                        if ($this->draining) {
                            $this->retire();
                            $this->connection->end();
                            return;
                        }
                        $this->busy = false;
                        $this->connection->resumeRead();
                        $this->schedule();
                    };
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
            $encoder = new ResponseEncoder(
                $upgrade?->response ?? $response,
                $request,
                $this->draining || $this->requests >= $this->maxRequests
                    || ($this->peerEof && $this->parser->phase() === 'idle'),
                $upgrade !== null,
            );
            $this->output = $encoder->bytes();
            $this->afterOutput = function () use ($encoder, $upgrade, $request): void {
                if ($upgrade !== null) {
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
                if ($encoder->close || $this->draining) {
                    $this->retire();
                    $this->connection->end();
                    return;
                }
                $this->busy = false;
                $this->releaseRequest();
                $this->responseStarted = false;
                $this->armDeadline($this->parser->phase());
                $this->connection->resumeRead();
                if ($this->parser->phase() !== 'idle' || $this->peerEof) {
                    $this->schedule();
                }
            };
            $this->pump();
        } catch (HttpException $error) {
            $this->fail($error->status);
        } catch (\Throwable) {
            $this->fail(500);
        } finally {
            $releaseActivity();
        }
    }

    private function pump(): void
    {
        if ($this->pumping || $this->retired || $this->output === null || $this->connection->isClosed()) {
            return;
        }
        $releaseActivity = $this->connection->beginActivity();
        $this->pumping = true;
        try {
            for ($budget = 0; $budget < 64; ++$budget) {
                if ($this->retired || $this->connection->isClosed()) {
                    return;
                }
                if ($this->pending === '') {
                    if (!$this->output->valid()) {
                        $this->output = null;
                        $finish = $this->afterOutput;
                        $this->afterOutput = null;
                        $finish?->__invoke();
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
                    $this->output->next();
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
            $releaseActivity();
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
        $this->afterOutput = function (): void {
            $this->retire();
            $this->connection->end();
        };
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
        $this->afterOutput = null;
    }

    private function releaseRequest(): void
    {
        $request = $this->activeRequest;
        $this->activeRequest = null;
        $request?->cancellation->cancel();
    }
}
