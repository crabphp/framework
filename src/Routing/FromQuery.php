<?php

declare(strict_types=1);

namespace Crab\Routing;

/**
 * Marks an action's parameter as read from the query string. On a field,
 * that one value; on a class or on Data described with #[Fields], the
 * whole query string:
 *
 *     public function handle(#[FromQuery] int $page = 1, #[FromQuery] ?string $search = null)
 *     public function handle(#[FromQuery] OrderFilter $filter)
 *
 * Without it, a field is a parameter of the path and a class is the body.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class FromQuery {}
