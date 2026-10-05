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
    public function __construct(public ?int $min = null, public ?int $max = null)
    {
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
        } else {
            // Characters, not bytes, without needing the mbstring extension.
            $length = (int) preg_match_all('/./su', (string) $value);
            $unit = 'character(s)';
            $verb = 'be';
        }
        if ($this->min !== null && $length < $this->min) {
            return "must {$verb} at least {$this->min} {$unit}";
        }
        if ($this->max !== null && $length > $this->max) {
            return "must {$verb} at most {$this->max} {$unit}";
        }
        return null;
    }

    public function schema(string $type): array
    {
        $names = $type === 'array' ? ['minItems', 'maxItems'] : ['minLength', 'maxLength'];
        $schema = [];
        if ($this->min !== null) {
            $schema[$names[0]] = $this->min;
        }
        if ($this->max !== null) {
            $schema[$names[1]] = $this->max;
        }
        return $schema;
    }
}
