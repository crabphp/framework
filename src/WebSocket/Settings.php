<?php

declare(strict_types=1);

namespace Crab\WebSocket;

use Crab\Engine\WebSocket\Config;

/**
 * Framework limits mapped onto the engine's frame and queue limits.
 * Defaults live here once, also for a router used without an application.
 */
final readonly class Settings
{
    public const DEFAULTS = [
        'ping' => 25,
        'max_message' => 65_536,
        'send_timeout' => 5.0,
        'max_connections' => 256,
    ];

    public Config $engine;

    public function __construct(
        public float $ping = self::DEFAULTS['ping'],
        public int $maxMessage = self::DEFAULTS['max_message'],
        public float $sendTimeout = self::DEFAULTS['send_timeout'],
        public int $maxConnections = self::DEFAULTS['max_connections'],
        float $closeTimeout = 5.0,
    ) {
        if (!is_finite($ping) || $ping < 0) {
            throw new \InvalidArgumentException(
                'Set "websocket.ping" to finite seconds greater than or equal to zero; zero switches pings off.',
            );
        }
        if ($maxMessage < 1 || $maxMessage > 262_144) {
            throw new \InvalidArgumentException(
                'Set "websocket.max_message" to bytes between 1 and 262144 (256 KiB); the bounded socket inbox cannot hold a larger message.',
            );
        }
        if (!is_finite($sendTimeout) || $sendTimeout <= 0) {
            throw new \InvalidArgumentException('Set "websocket.send_timeout" to finite seconds greater than zero.');
        }
        if ($maxConnections < 1) {
            throw new \InvalidArgumentException(
                'Set "websocket.max_connections" to a positive number of sockets per worker.',
            );
        }
        $frame = min(16_384, $maxMessage);
        $frames = max(16, (int) ceil($maxMessage / $frame));
        $this->engine = new Config(
            maxFrameBytes: max(125, $maxMessage),
            maxMessageBytes: $maxMessage,
            // One transport read can contain the end of a frame and the start of another.
            maxBufferedInputBytes: max(65_536, $maxMessage + 14) + 65_536,
            maxQueuedOutputBytes: max(256, 2 * ($maxMessage + (10 * $frames))),
            maxQueuedFrames: 2 * $frames,
            outgoingFrameBytes: $frame,
            pingInterval: $ping === 0.0 ? null : $ping,
            closeTimeout: $closeTimeout,
        );
    }
}
