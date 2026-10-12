<?php

declare(strict_types=1);

namespace Crab\Testing;

use Crab\Contracts\Auth\Identity;
use Crab\Contracts\WebSocket\Binary;
use Crab\Engine\EventLoop;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\WriteResult;
use Crab\Engine\Server\HttpServer;
use Crab\Engine\Suspension;
use Crab\Http\EngineHandler;
use Crab\Http\Request;
use Crab\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A WebSocket client over a socket pair. The other end runs the real HTTP
 * adapter, middleware and engine session, with no listening port.
 */
final class TestSocket
{
    private string $input = '';
    private string $fragment = '';
    private int $fragmentType = 0;
    private bool $handshake = false;
    private bool $upgraded = false;
    private bool $closed = false;
    private bool $settled = false;
    private bool $disposed = false;
    private int $wireStatus = 0;
    private array $wireHeaders = [];
    private bool $closeSent = false;
    private ?int $closeCode = null;
    private ?Suspension $waiter = null;
    private ?ResponseInterface $response = null;
    private readonly \WeakReference $owner;
    /**
     * @var \SplQueue<array|string|Binary>
     */
    private \SplQueue $messages;

    private function __construct(
        private readonly EventLoop $loop,
        private readonly Connection $server,
        private readonly Connection $client,
        private readonly string $route,
    ) {
        $this->messages = new \SplQueue();
        $this->owner = \WeakReference::create($this);
        $owner = $this->owner;
        $server->onSettled(static function () use ($owner): void {
            if (($socket = $owner->get()) !== null) {
                $socket->settled = true;
                $socket->wake();
            }
        });
        $client->onData(static fn (Connection $connection, string $data) => $owner->get()?->read($data));
        $client->onClose(static function () use ($owner): void {
            if (($socket = $owner->get()) !== null) {
                $socket->closed = true;
                $socket->wake();
            }
        });
        $client->onEof(static fn () => $client->close());
    }

    /**
     * @internal Called by Api with the headers and identity it normally uses.
     */
    public static function connect(RequestHandlerInterface $app, Request $request): self
    {
        $loop = EventLoop::current();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) {
            throw new \RuntimeException('Cannot create a socket pair for the WebSocket test.');
        }
        $server = new Connection($loop, $pair[0]);
        $client = new Connection($loop, $pair[1]);
        $socket = new self($loop, $server, $client, 'WS ' . $request->getUri()->getPath());
        $socket->startHttp($app, $request);
        try {
            if (!$socket->waitUntil(fn (): bool => $socket->handshake || $socket->closed, 1.0)) {
                throw new \RuntimeException('The WebSocket test handshake did not finish within one second.');
            }
            if (!$socket->upgraded) {
                $response = $socket->refusedResponse();
                throw new WebSocketRefused(new ApiResponse($response, 'GET ' . $request->getRequestTarget()));
            }
            return $socket;
        } catch (\Throwable $error) {
            $socket->close();
            throw $error;
        }
    }

    private function startHttp(RequestHandlerInterface $app, Request $request): void
    {
        $user = $request->getAttribute(Identity::ATTRIBUTE);
        $owner = $this->owner;
        $handler = new EngineHandler(static function (ServerRequestInterface $received) use ($app, $user, $owner): ResponseInterface {
            if ($user !== null) {
                $received = $received->withAttribute(Identity::ATTRIBUTE, $user);
            }
            $response = $app->handle($received);
            if (($socket = $owner->get()) !== null) {
                $socket->response = $response;
            }
            return $response;
        });
        $server = new HttpServer($handler);
        $server->attach($this->server);
        $head = "GET {$request->getRequestTarget()} HTTP/1.1\r\n";
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $head .= "{$name}: {$value}\r\n";
            }
        }
        $this->client->tryWrite($head . "\r\n");
    }

    private function refusedResponse(): ResponseInterface
    {
        if ($this->response !== null && $this->response->getStatusCode() === $this->wireStatus) {
            return $this->response;
        }
        return new Response($this->wireStatus, $this->wireHeaders, $this->input);
    }

    public function send(array|string|Binary $message): void
    {
        if ($message instanceof Binary) {
            $this->frame(2, $message->bytes);
            return;
        }
        $payload = is_array($message) ? json_encode($message, JSON_THROW_ON_ERROR) : $message;
        $this->frame(1, $payload);
    }

    public function sendBinary(string $bytes): void
    {
        $this->frame(2, $bytes);
    }

    public function receive(float $timeout = 1.0): array|string|Binary|null
    {
        $this->waitUntil(fn (): bool => !$this->messages->isEmpty() || $this->closed, $timeout);
        return $this->messages->isEmpty() ? null : $this->messages->dequeue();
    }

    public function assertReceived(array|string|Binary $message): static
    {
        $received = $this->receive();
        if ($message instanceof Binary && $received instanceof Binary) {
            self::assertSame($message->bytes, $received->bytes, 'The WebSocket did not receive the expected binary message.');
        } else {
            self::assertSame($message, $received, 'The WebSocket did not receive the expected message.');
        }
        return $this;
    }

    public function assertNothingReceived(): static
    {
        self::assertSame(null, $this->receive(0.01), 'The WebSocket received an unexpected message.');
        return $this;
    }

    public function assertClosed(?int $code = null): static
    {
        $this->waitUntil(fn (): bool => $this->closed, 1.0);
        self::assertSame(true, $this->closed, 'The WebSocket is still open.');
        if ($code !== null) {
            self::assertSame($code, $this->closeCode, 'The WebSocket closed with another code.');
        }
        return $this;
    }

    public function close(): void
    {
        if ($this->disposed) {
            return;
        }
        try {
            if (!$this->closed && $this->upgraded) {
                $this->frame(8, pack('n', 1000));
                $this->waitUntil(fn (): bool => $this->closed, 1.0);
            }
            // A noncooperating peer must not retain transport ownership.
            $this->client->close();
            $this->server->close();
            if (!$this->waitUntil(fn (): bool => $this->settled, 1.0)) {
                self::assertSame(true, false, "The action of {$this->route} did not settle within one second after close. End socket work on cancellation and finish cleanup promptly.");
            }
        } finally {
            $this->dispose();
            if (\Fiber::getCurrent() === null) {
                // Finish the main-context driver without cancelling another
                // socket or an independently owned task. They can run again.
                $this->loop->defer($this->loop->stop(...));
                $this->loop->run();
            }
        }
    }

    /**
     * A timeout returns no message; it does not close the test connection.
     */
    private function waitUntil(\Closure $ready, float $timeout): bool
    {
        if (!is_finite($timeout) || $timeout < 0) {
            throw new \InvalidArgumentException('Set the WebSocket test timeout to finite seconds greater than or equal to zero.');
        }
        $deadline = hrtime(true) / 1e9 + $timeout;
        while (!$ready()) {
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                return false;
            }
            if ($this->waiter !== null) {
                throw new \LogicException('Receive from one TestSocket consumer at a time.');
            }
            $this->waiter = $this->loop->getSuspension();
            $timer = $this->loop->delay($remaining, $this->wake(...));
            try {
                $this->waiter->suspend();
            } finally {
                $this->loop->cancel($timer);
                $this->waiter = null;
            }
        }
        return true;
    }

    private function wake(): void
    {
        $waiter = $this->waiter;
        $this->waiter = null;
        $waiter?->resume();
    }

    /**
     * Test clients send masked frames, so protocol checks run as on TCP.
     */
    private function frame(int $opcode, string $payload): void
    {
        if ($this->closed) {
            throw new \LogicException('The test WebSocket is closed; open another socket before sending.');
        }
        if ($opcode === 8) {
            if ($this->closeSent) { return; }
            $this->closeSent = true;
        }
        $length = strlen($payload);
        $mask = random_bytes(4);
        $header = chr(128 | $opcode);
        $header .= $length < 126 ? chr(128 | $length)
            : ($length <= 65535 ? chr(254) . pack('n', $length) : chr(255) . pack('NN', 0, $length));
        $result = $this->client->tryWrite($header . $mask . ($payload ^ str_repeat($mask, intdiv($length + 3, 4))));
        if ($opcode === 8 && $result === WriteResult::Closed) {
            return;
        }
        if ($result !== WriteResult::Accepted) {
            throw new \RuntimeException('The test client could not queue its frame. Let the action receive before sending more data.');
        }
    }

    /**
     * Reads server frames, replying to Ping and Close like a live client.
     */
    private function read(string $data): void
    {
        $this->input .= $data;
        if (!$this->handshake && !$this->readHandshake()) {
            return;
        }
        if (!$this->upgraded) {
            return;
        }
        while ($this->readFrame()) {
        }
    }

    private function readHandshake(): bool
    {
        $end = strpos($this->input, "\r\n\r\n");
        if ($end === false) {
            return false;
        }
        $head = substr($this->input, 0, $end);
        $lines = explode("\r\n", $head);
        $this->wireStatus = (int) explode(' ', array_shift($lines), 3)[1];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $this->wireHeaders[strtolower($name)][] = trim($value);
        }
        $this->upgraded = $this->wireStatus === 101;
        $this->input = substr($this->input, $end + 4);
        $this->handshake = true;
        $this->wake();
        return true;
    }

    private function readFrame(): bool
    {
        if (strlen($this->input) < 2) {
            return false;
        }
        $first = ord($this->input[0]);
        $opcode = $first & 15;
        $length = ord($this->input[1]) & 127;
        $offset = 2;
        if ($length === 126) {
            if (strlen($this->input) < 4) { return false; }
            $length = unpack('n', $this->input, 2)[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($this->input) < 10) { return false; }
            $length = unpack('N', $this->input, 6)[1];
            $offset = 10;
        }
        if (strlen($this->input) < $offset + $length) {
            return false;
        }
        $payload = substr($this->input, $offset, $length);
        $this->input = substr($this->input, $offset + $length);
        $this->handleFrame($first, $opcode, $payload);
        return true;
    }

    private function handleFrame(int $first, int $opcode, string $payload): void
    {
        if ($opcode === 9) {
            $this->frame(10, $payload);
        } elseif ($opcode === 8) {
            $this->closeCode = $payload === '' ? 1005 : unpack('n', $payload)[1];
            $this->frame(8, $payload);
            $this->closed = true;
            $this->wake();
        } elseif ($opcode < 8) {
            if ($opcode !== 0) {
                $this->fragmentType = $opcode;
            }
            $this->fragment .= $payload;
            if (($first & 128) !== 0) {
                $this->enqueueMessage();
            }
        }
    }

    private function enqueueMessage(): void
    {
        $message = $this->fragment;
        if ($this->fragmentType === 1) {
            $decoded = json_decode($message, true);
            if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                $message = $decoded;
            }
        }
        if ($this->fragmentType === 2) {
            $message = new Binary($message);
        }
        $this->messages->enqueue($message);
        $this->fragment = '';
        $this->wake();
    }

    /**
     * Own both streams, including when an assertion or handshake fails.
     */
    private function dispose(): void
    {
        $this->disposed = true;
        $this->client->close();
        $this->server->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    private static function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if (class_exists(\PHPUnit\Framework\Assert::class)) {
            \PHPUnit\Framework\Assert::assertSame($expected, $actual, $message);
        } elseif ($expected !== $actual) {
            throw new \LogicException($message);
        }
    }
}
