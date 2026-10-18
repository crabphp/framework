<?php

declare(strict_types=1);

namespace Crab\Engine\WebSocket;

/**
 * WebSocket bounds and optional peer-silence deadlines. A paused reader is
 * not timed out: the caller must bound application pauses and suspended
 * message handlers. Resuming starts a fresh ping and idle interval.
 */
final readonly class Config
{
    public function __construct(
        public int $maxFrameBytes = 1_048_576,
        public int $maxMessageBytes = 4_194_304,
        public int $maxBufferedInputBytes = 2_097_152,
        public int $maxQueuedOutputBytes = 1_048_576,
        public int $maxQueuedFrames = 1024,
        public int $outgoingFrameBytes = 16_384,
        public int $framesPerTurn = 64,
        public float $closeTimeout = 5.0,
        public ?float $pingInterval = null,
        public ?float $idleTimeout = null,
        public int $maxWritableWaiters = 64,
    ) {
        foreach ([
            $maxFrameBytes,
            $maxMessageBytes,
            $maxBufferedInputBytes,
            $maxQueuedOutputBytes,
            $maxQueuedFrames,
            $outgoingFrameBytes,
            $framesPerTurn,
            $maxWritableWaiters,
        ] as $limit) {
            if ($limit < 1) {
                throw new \InvalidArgumentException('WebSocket limits must be positive.');
            }
        }

        if (
            $maxFrameBytes > 2_147_483_647
            || $maxBufferedInputBytes < ($maxFrameBytes + 14)
            || !is_finite($closeTimeout)
            || $closeTimeout <= 0
        ) {
            throw new \InvalidArgumentException('Invalid WebSocket frame buffer or close timeout.');
        }
        foreach ([
            $pingInterval,
            $idleTimeout,
            $pingInterval === null || $idleTimeout !== null ? null : $pingInterval * 2,
        ] as $seconds) {
            if ($seconds !== null && (!is_finite($seconds) || $seconds <= 0)) {
                throw new \InvalidArgumentException(
                    'Set WebSocket ping and idle intervals to positive finite seconds, or null to disable them.',
                );
            }
        }
    }
}
