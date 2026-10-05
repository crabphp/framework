<?php

declare(strict_types=1);

namespace Crab\Validation;

/**
 * Says what a list holds, which PHP's array type cannot: on a constructor
 * parameter, #[ListOf(Tag::class)] public array $tags; on a route handler
 * that returns an array, what each item of the response is.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
final readonly class ListOf
{
    /**
     * @param string $type A class name, or int, float, string or bool.
     */
    public function __construct(public string $type)
    {
    }
}
