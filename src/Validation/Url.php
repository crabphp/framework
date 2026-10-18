<?php

declare(strict_types=1);

namespace Crab\Validation;

use Crab\Contracts\Validation\Rule;

/**
 * The text is an http or https address.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Url implements Rule
{
    public function check(mixed $value): ?string
    {
        $valid =
            is_string($value)
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && preg_match('~^https?://~i', $value) === 1;
        return $valid ? null : 'must be a web address starting with http:// or https://';
    }
}
