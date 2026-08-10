<?php

namespace Crab\Engine\Connection;

use Crab\Engine\Connection\ConnectionState;
use Crab\Engine\EventLoop\EventLoop;
use Crab\Engine\Protocol\Protocol;

final class TcpConnection
{
    public private(set) ConnectionState $state = ConnectionState::Established;

    public int $maxSendBufferSize = 1_048_567 {
        set {
            if ($value < 1) {
                throw new \InvalidArgumentException('maxSendBufferSize must be positive');
            }

            $this->maxSendBufferSize = $value;
        }
    }

    private string $sendBuffer = '';
    private string $recvBuffer = '';

    public mixed $onMessage = null;
    public mixed $onClose = null;
    public mixed $onError = null;
    public mixed $onBufferFull = null;
    public mixed $onBufferDrain = null;

    private ?Protocol $protocol = null;

    public function __construct(
        private EventLoop $loop,
        private mixed $socket,
        public readonly string $remoteAddress,
    ) {
        stream_set_blocking($this->socket, false);
        $this->loop->onReadable($this->socket, $this->read(...));
    }

    public function useProtocol(?Protocol $protocol): void
    {
        $this->protocol = $protocol;
    }

    public function read(): void
    {
        $data = @fread($this->socket, 8192);

        if ($data === '' || $data === false) {
            if (feof($this->socket)) {
                $this->close();
            }

            return;
        }

        $this->recvBuffer .= $data;

        if ($this->protocol === null) {
            $message = $this->recvBuffer;
            $this->recvBuffer = '';
            $this->emitMessage($message);
            return;
        }

        while ($this->recvBuffer !== '') {
            $length = $this->protocol->input($this->recvBuffer, $this);

            if ($length === 0) {
                break;
            }

            if ($length < 0) {
                $this->close();
                break;
            }

            if (strlen($this->recvBuffer) < $length) {
                break;
            }

            $packet = substr($this->recvBuffer, 0, $length);
            $this->recvBuffer = substr($this->recvBuffer, $length);

            $message = $this->protocol->decode($packet, $this);

            $this->emitMessage($message);
        }
    }

    public function emitMessage(string $message): void
    {
        if ($this->onMessage !== null) {
            ($this->onMessage)($this, $message);
        }
    }

    public function send(mixed $data): bool
    {
        if ($this->protocol !== null) {
            $data = $this->protocol->encode($data, $this);
        }

        return $this->writeBytes((string) $data);
    }

    public function writeBytes(string $data): bool
    {
        if ($this->state !== ConnectionState::Established) {
            return false;
        }

        if ($this->sendBuffer === '') {
            $written = @fwrite($this->socket, $data);

            if ($written === strlen($data)) {
                return true;
            }

            if ($written === false) {
                $this->close();
                return false;
            }

            $this->sendBuffer = substr($data, $written);
            $this->loop->onWritable($this->socket, $this->write(...));

            return true;
        }

        if ((strlen($this->sendBuffer) + strlen($data)) > $this->maxSendBufferSize) {
            if ($this->onBufferFull !== null) {
                ($this->onBufferFull)($this);
            }
            return false;
        }

        $this->sendBuffer .= $data;

        return false;
    }

    public function write(): void
    {
        if ($this->sendBuffer === '') {
            $this->loop->offWritable($this->socket);
            return;
        }

        $written = @fwrite($this->socket, $this->sendBuffer);

        if ($written === false || $written === 0) {
            $this->close();
            return;
        }

        $this->sendBuffer = substr($this->sendBuffer, $written);

        if ($this->sendBuffer === '') {
            $this->loop->offWritable($this->socket);

            if ($this->onBufferDrain !== null) {
                ($this->onBufferDrain)($this);
            }

            if ($this->state === ConnectionState::Closing) {
                $this->close();
            }
        }
    }

    public function close(): void
    {
        if ($this->state === ConnectionState::Closed) {
            return;
        }

        $this->state = ConnectionState::Closed;

        $this->loop->offReadable($this->socket);
        $this->loop->offWritable($this->socket);

        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        if ($this->onClose !== null) {
            ($this->onClose)($this);
        }
    }
}
