<?php

declare(strict_types=1);

namespace Crab\Engine\WebSocket;

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
    ) {
        foreach ([$maxFrameBytes, $maxMessageBytes, $maxBufferedInputBytes, $maxQueuedOutputBytes,
            $maxQueuedFrames, $outgoingFrameBytes, $framesPerTurn] as $limit) {
            if ($limit < 1) {
                throw new \InvalidArgumentException('WebSocket limits must be positive.');
            }
        }

        if ($maxFrameBytes > 2_147_483_647 || $maxBufferedInputBytes < $maxFrameBytes + 14
            || !is_finite($closeTimeout) || $closeTimeout <= 0) {
            throw new \InvalidArgumentException('Invalid WebSocket frame buffer or close timeout.');
        }
    }
}
