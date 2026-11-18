<?php

declare(strict_types=1);

namespace Crab\Contracts\Http;

/**
 * Strict reading of one request's query string and body, chosen for it
 * by a DecodePolicy before any middleware runs. Where the ordinary
 * reading keeps what PHP would, the last of two fields of one name and as
 * many fields as max_input_vars allows, this refuses what is ambiguous or
 * cut short:
 *
 *     400   a repeated scalar field, "tags[]" mixed with "tags[0]", an
 *           index that skips, a malformed name, a JSON body that is not
 *           one object or repeats a member's name
 *     413   more fields, deeper nesting or a longer name than allowed
 *     415   a body whose type or charset is not one it reads
 *
 * The limits are the most the reader builds, whatever the declared input
 * later accepts.
 */
final readonly class Decoding
{
    /**
     * @param int $maxDepth How deep fields may nest: "a[b][c]" is 3.
     * @param int $maxNodes How many values a query string or a body may hold, nested ones included.
     * @param int $maxNameBytes The longest field name, as written: "lines[0][quantity]".
     * @param int $maxItems The most items one list or object may hold.
     */
    public function __construct(
        public int $maxDepth = 32,
        public int $maxNodes = 20_000,
        public int $maxNameBytes = 256,
        public int $maxItems = 10_000,
    ) {
        foreach ([
            'maxDepth' => $maxDepth,
            'maxNodes' => $maxNodes,
            'maxNameBytes' => $maxNameBytes,
            'maxItems' => $maxItems,
        ] as $name => $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException("A decoding's {$name} is at least 1.");
            }
        }
    }
}
