<?php

declare(strict_types=1);

namespace Crab\Engine\Server;

use Crab\Engine\Http\HttpSession;
use Crab\Engine\Network\Connection;

final readonly class HttpServer
{
    private \Closure $handler;

    /**
     * Handler receives Http\Request and returns Http\Response, Http\Upgrade, or a text string.
     */
    public function __construct(
        callable $handler,
        private int $maxHeaderBytes = 16_384,
        private int $maxBodyBytes = 1_048_576,
        private int $maxRequests = 1000,
        private float $headerTimeout = 10,
        private float $bodyTimeout = 30,
        private float $idleTimeout = 30,
        private float $responseTimeout = 30,
    ) {
        if ($maxHeaderBytes < 1 || $maxBodyBytes < 0 || $maxRequests < 1) {
            throw new \InvalidArgumentException('Invalid HTTP limits.');
        }
        foreach ([$headerTimeout, $bodyTimeout, $idleTimeout, $responseTimeout] as $timeout) {
            if (!is_finite($timeout) || $timeout <= 0) {
                throw new \InvalidArgumentException('HTTP timeouts must be finite and positive.');
            }
        }
        $this->handler = $handler(...);
    }

    public function __invoke(Connection $connection): void
    {
        $this->attach($connection);
    }

    public function attach(Connection $connection): void
    {
        new HttpSession(
            $connection,
            $this->handler,
            $this->maxHeaderBytes,
            $this->maxBodyBytes,
            $this->maxRequests,
            $this->headerTimeout,
            $this->bodyTimeout,
            $this->idleTimeout,
            $this->responseTimeout,
        );
    }
}
