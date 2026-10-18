<?php

declare(strict_types=1);

namespace Crab\Validation;

use Crab\Contracts\Validation\Rule;

/**
 * The text is an email address.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Email implements Rule
{
    public function check(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false
            ? null
            : 'must be an email address';
    }
}
