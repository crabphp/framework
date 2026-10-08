<?php

declare(strict_types=1);

namespace Crab\Validation;

use Crab\Contracts\Validation\Rule;

/**
 * The smallest and largest a number may be: #[Range(min: 0, max: 150)].
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Range implements Rule
{
    public function __construct(public int|float|null $min = null, public int|float|null $max = null)
    {
        if ($min === null && $max === null) {
            throw new \LogicException('Range needs a min, a max or both.');
        }
    }

    public function check(mixed $value): ?string
    {
        if (!is_int($value) && !is_float($value)) {
            return 'must be a number';
        }
        if ($this->min !== null && $value < $this->min) {
            return "must be at least {$this->min}";
        }
        if ($this->max !== null && $value > $this->max) {
            return "must be at most {$this->max}";
        }
        return null;
    }
}
