<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Cast;

/**
 * A column that holds JSON, read into the record's array and written back
 * as JSON text:
 *
 *     public function __construct(#[Json] public array $settings) {}
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Json implements Cast
{
    public function read(mixed $value): mixed
    {
        if (!is_string($value)) {
            // A driver that decodes JSON itself has done the work already.
            return $value;
        }
        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \UnexpectedValueException('it does not hold valid JSON');
        }
    }

    public function write(mixed $value): mixed
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
