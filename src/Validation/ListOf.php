<?php

declare(strict_types=1);

namespace Crab\Validation;

/**
 * Says what a list holds, which PHP's array type cannot: on a constructor
 * parameter, #[ListOf(Tag::class)] public array $tags. Given the fields
 * of an object in place of a class, #[ListOf(['id' => 'int'])], each
 * item is a Data with those fields; see Fields for how they are described.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class ListOf
{
    /**
     * @param string|array<string, mixed> $type A class name, or int, float, string or bool, or the fields of an object.
     */
    public function __construct(public string|array $type)
    {
    }
}
