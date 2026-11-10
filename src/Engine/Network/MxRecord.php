<?php

declare(strict_types=1);

namespace Crab\Engine\Network;

/**
 * An MX answer. A lower preference is tried first; "." declines mail.
 */
final readonly class MxRecord
{
    public function __construct(
        public int $preference,
        public string $exchange,
    ) {}
}
