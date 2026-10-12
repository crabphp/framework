<?php

declare(strict_types=1);

namespace Crab\Contracts\Broadcast;

/**
 * A bounded channel name, shared by real and fake broadcasters so tests
 * refuse the same invalid destinations as the application.
 */
final readonly class Channel
{
    public function __construct(public string $name)
    {
        if ($name === '' || strlen($name) > 200 || preg_match('/[\p{Cc}\p{Cf}]/u', $name) !== 0) {
            throw new \InvalidArgumentException('Use a UTF-8 broadcast channel of 1 to 200 bytes without control characters.');
        }
    }
}
