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
    /**
     * An everyday address, of which filter_var() accepts every one, in a
     * tenth of its time. Anything else is left to filter_var() to decide.
     */
    private const PLAIN =
        '/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~-]{1,64}(?:\.[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~-]{1,64})*'
            . '@(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.){1,8}[A-Za-z]{2,63}$/D';

    public function check(mixed $value): ?string
    {
        if (!is_string($value)) {
            return 'must be an email address';
        }
        $plain = strlen($value) <= 254 && strcspn($value, '@') <= 64 && preg_match(self::PLAIN, $value) === 1;
        return $plain || filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : 'must be an email address';
    }
}
