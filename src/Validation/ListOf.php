<?php

declare(strict_types=1);

namespace Crab\Validation;

/**
 * Says what a list holds, which PHP's array type cannot: on a constructor
 * parameter, #[ListOf(Tag::class)] public array $tags.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class ListOf
{
    /**
     * @param string $type A class name, or int, float, string or bool.
     */
    public function __construct(public string $type)
    {
    }
}
