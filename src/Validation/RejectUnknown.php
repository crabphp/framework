<?php

declare(strict_types=1);

namespace Crab\Validation;

/**
 * Refuses data that holds a field the input does not declare, in place of
 * ignoring it. On a class, for that class's own fields; on a Data
 * parameter described with #[Fields], for every object it describes:
 *
 *     #[RejectUnknown]
 *     final readonly class ChangeSetting
 *
 *     #[Fields(['name' => 'string']), RejectUnknown] Data $changes
 *
 * A field it refuses is reported by its name as "is not expected".
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PARAMETER)]
final readonly class RejectUnknown {}
