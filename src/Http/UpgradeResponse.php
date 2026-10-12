<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Engine\Http\Response as EngineResponse;
use Crab\Engine\Http\Upgrade;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-7 response carrying a deferred connection handover. Middleware
 * can inspect and clone its headers before the engine resumes the waiting action.
 */
final class UpgradeResponse extends Message implements ResponseInterface
{
    private int $status = 101;
    private string $reason = '';

    private readonly ?UpgradeLifetime $lifetime;

    public function __construct(private readonly Upgrade $upgrade, ?\Closure $discard = null)
    {
        $this->lifetime = $discard === null ? null : new UpgradeLifetime($discard);
        foreach ($upgrade->response->headers as $name => $value) {
            $this->put($name, $value, false);
        }
    }

    /**
     * Ends a handover that a non-network caller cannot perform.
     *
     * @internal
     */
    public function discard(): void
    {
        $this->lifetime?->discard();
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        if ($code < 100 || $code > 599) {
            throw new \InvalidArgumentException('HTTP status must be between 100 and 599.');
        }
        $new = clone $this;
        $new->status = $code;
        $new->reason = $reasonPhrase;
        return $new;
    }

    public function getReasonPhrase(): string
    {
        return $this->reason !== '' ? $this->reason : EngineResponse::reason($this->status);
    }

    /**
     * Uses the final pipeline headers. A middleware changing the status
     * cancels the upgrade and is handled as an ordinary HTTP response.
     */
    public function toUpgrade(): Upgrade
    {
        if ($this->status !== 101 || $this->getBody()->getSize() !== 0) {
            throw new \LogicException('A WebSocket upgrade must keep its bodyless 101 response. Return an HTTP error response to refuse it.');
        }
        $this->lifetime?->converted();
        return new Upgrade(new EngineResponse(101, $this->getHeaders()), $this->upgrade->handler);
    }
}
