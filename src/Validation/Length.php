<?php

declare(strict_types=1);

namespace Crab\Validation;

use Crab\Contracts\Validation\Rule;

/**
 * How long a text may be, in characters, or how many items a list may
 * hold: #[Length(min: 2, max: 50)].
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Length implements Rule
{
    public function __construct(
        public ?int $min = null,
        public ?int $max = null,
    ) {
        if ($min === null && $max === null) {
            throw new \LogicException('Length needs a min, a max or both.');
        }
    }

    public function check(mixed $value): ?string
    {
        if (is_array($value)) {
            $length = count($value);
            $unit = 'item(s)';
            $verb = 'have';
        } elseif (is_string($value)) {
            // Characters, not bytes, without needing the mbstring extension.
            $length = preg_match_all('/./su', $value);
            if ($length === false) {
                // Not text at all: it has no length in characters to compare.
                return 'must be valid UTF-8 text';
            }
            $unit = 'character(s)';
            $verb = 'be';
        } else {
            return 'must be text or a list';
        }
        if ($this->min !== null && $length < $this->min) {
            return "must {$verb} at least {$this->min} {$unit}";
        }
        if ($this->max !== null && $length > $this->max) {
            return "must {$verb} at most {$this->max} {$unit}";
        }
        return null;
    }
}
