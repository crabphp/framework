<?php

declare(strict_types=1);

namespace Crab\Contracts\Validation;

/**
 * A check on one value beyond its type, written as an attribute on a
 * constructor parameter: #[Email] public string $email. Implement it on an
 * attribute class of your own to add a rule.
 */
interface Rule
{
    /**
     * @param mixed $value Already of the parameter's type, and never null.
     * @return string|null What is wrong, worded to follow the field's name ("must be ..."), or null when nothing is.
     */
    public function check(mixed $value): ?string;

    /**
     * @param string $type The value's JSON Schema type: string, integer, number, boolean, array or object.
     * @return array<string, mixed> The JSON Schema keywords that say the same as check().
     */
    public function schema(string $type): array;
}
