<?php

declare(strict_types=1);

namespace Crab\Validation;

use Crab\Contracts\Validation\Rule;

/**
 * The text matches a regular expression, written without delimiters:
 * #[Pattern('^[a-z0-9-]+$', 'must be lowercase letters, digits and dashes')].
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Pattern implements Rule
{
    /**
     * @param string $message What to tell the client, worded to follow the field's name.
     */
    public function __construct(public string $expression, public string $message = 'does not have the expected format')
    {
        if (@preg_match($this->delimited(), '') === false) {
            throw new \LogicException("Pattern was given an invalid regular expression: {$expression}");
        }
    }

    public function check(mixed $value): ?string
    {
        return is_string($value) && preg_match($this->delimited(), $value) === 1 ? null : $this->message;
    }

    private function delimited(): string
    {
        return '~' . str_replace('~', '\~', $this->expression) . '~u';
    }
}
