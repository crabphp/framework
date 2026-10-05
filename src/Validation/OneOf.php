<?php

declare(strict_types=1);

namespace Crab\Validation;

use Crab\Contracts\Validation\Rule;

/**
 * The value is one of those listed: #[OneOf('draft', 'published')]. When
 * the choices are used in more than one place, a backed enum as the
 * parameter's type says the same and needs no rule.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class OneOf implements Rule
{
    /**
     * @var list<string|int>
     */
    public array $values;

    public function __construct(string|int ...$values)
    {
        if ($values === []) {
            throw new \LogicException('OneOf needs at least one value.');
        }
        $this->values = array_values($values);
    }

    public function check(mixed $value): ?string
    {
        return in_array($value, $this->values, true) ? null : 'must be one of: ' . implode(', ', $this->values);
    }

    public function schema(string $type): array
    {
        return ['enum' => $this->values];
    }
}
