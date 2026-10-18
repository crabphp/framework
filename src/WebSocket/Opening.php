<?php

declare(strict_types=1);

namespace Crab\WebSocket;

use Crab\Contracts\Http\Request;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\EventLoop;
use Crab\Engine\Http\Request as EngineRequest;
use Crab\Engine\Http\Response as EngineResponse;
use Crab\Engine\Http\Upgrade;
use Crab\Engine\Network\Connection;
use Crab\Engine\Suspension;
use Crab\Engine\WebSocket\MessageType;
use Crab\Engine\WebSocket\WebSocketSession;
use Crab\Http\Response;
use Crab\Http\UpgradeResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * One reserved opening, from its HTTP action through handover and cleanup.
 *
 * @internal
 */
final class Opening
{
    private readonly EventLoop $loop;
    private readonly Socket $socket;
    private ?Suspension $pipeline;
    private bool $offered = false;
    private ?Connection $connection = null;
    private ?\Closure $releaseActivity = null;
    private ?\Closure $unsubscribe = null;
    private ?CancelledException $requestEndReason = null;

    public function __construct(
        Request $request,
        private ?EngineResponse $handshake,
        \Closure $action,
        private readonly Settings $settings,
        private readonly Admission $admission,
        private readonly string $route,
        ?LoggerInterface $logger,
        ?\Closure $memberships = null,
    ) {
        $this->loop = EventLoop::current();
        $this->pipeline = $this->loop->getSuspension();
        $this->socket = new Socket(
            $this->loop,
            $request,
            $this->offerUpgrade(...),
            $settings,
            $route,
            $logger,
            $memberships,
        );
        $this->unsubscribe = $request->cancellation()->subscribe($this->requestEnded(...));
        $this->loop->defer(fn() => $this->runAction($action));
    }

    public function response(): ResponseInterface
    {
        return $this->pipeline->suspend();
    }

    private function runAction(\Closure $action): void
    {
        try {
            $action($this->socket);
            $this->socket->accept();
        } catch (\Throwable $error) {
            if (!$this->offered) {
                $this->endBeforeAcceptance($error);
            } else {
                $this->endAfterAcceptance($error);
            }
        } finally {
            $this->finish();
        }
    }

    private function offerUpgrade(): void
    {
        $this->offered = true;
        $upgrade = new Upgrade($this->handshake, $this->attachSession(...));
        $response = new UpgradeResponse($upgrade, $this->socket->closed(...));
        $this->pipeline->resume($response);
        $this->pipeline = null;
        $this->handshake = null;
    }

    private function attachSession(Connection $transport, EngineRequest $request, string $unread): void
    {
        $this->connection = $transport;
        $this->releaseActivity = $transport->beginActivity();
        try {
            $session = new WebSocketSession(
                $transport,
                $this->deliver(...),
                $this->settings->engine,
                $this->sessionClosed(...),
            );
            $this->socket->attach($session, $transport);
            if ($unread !== '') {
                $session->receive($unread);
            }
        } catch (\Throwable $error) {
            $this->socket->closed();
            throw $error;
        }
    }

    private function deliver(WebSocketSession $session, string $message, MessageType $type): void
    {
        $this->socket->deliver($message, $type);
    }

    private function sessionClosed(): void
    {
        $this->socket->closed();
        $this->admission->release();
    }

    private function requestEnded(CancelledException $reason): void
    {
        $this->requestEndReason = $reason;
        // HTTP completes just before handover. Wait one turn so a successful
        // upgrade has attached its session before checking acceptance.
        $this->loop->defer($this->closeIfUnaccepted(...));
    }

    private function closeIfUnaccepted(): void
    {
        if (!$this->socket->isAccepted()) {
            $this->socket->closed();
        }
    }

    private function endBeforeAcceptance(\Throwable $error): void
    {
        if (
            $error instanceof SocketClosed
            || $this->socket->causedCancellation($error)
            || $error === $this->requestEndReason
        ) {
            // The engine has retired the request. An HTTP answer ends its
            // handler without reporting a disconnected client.
            $this->pipeline->resume(Response::noContent());
        } else {
            $this->pipeline->throw($error);
        }
        $this->pipeline = null;
    }

    private function endAfterAcceptance(\Throwable $error): void
    {
        if ($error instanceof SocketClosed || $this->socket->causedCancellation($error)) {
            return;
        }
        try {
            if ($this->connection !== null) {
                $failure = new \RuntimeException(
                    "The action of {$this->route} failed: {$error->getMessage()}",
                    previous: $error,
                );
                $this->connection->reportFailure('WebSocket action failed', $failure);
            }
        } finally {
            if ($this->socket->isAccepted() && !$this->socket->isClosed()) {
                $this->socket->close(1011, 'Action failed');
            }
        }
    }

    private function finish(): void
    {
        try {
            if ($this->socket->isAccepted() && !$this->socket->isClosed()) {
                $this->socket->close();
            }
        } finally {
            $this->release();
        }
    }

    private function release(): void
    {
        ($this->unsubscribe)();
        $this->unsubscribe = null;
        if (!$this->socket->isAccepted()) {
            $this->socket->closed();
            $this->admission->release();
        }
        $this->releaseActivity?->__invoke();
        $this->releaseActivity = null;
        $this->connection = null;
    }
}
