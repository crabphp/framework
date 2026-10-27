<?php

declare(strict_types=1);

namespace Crab\HttpClient;

/**
 * An answer's body is longer than the call allows; the transport names
 * the request in the TooLargeException it becomes.
 *
 * @internal
 */
final class TooLarge extends \RuntimeException
{
    /**
     * @param int|null $length What the answer said its length was, when it said so before its body.
     */
    public function __construct(
        public readonly int $limit,
        public readonly ?int $length = null,
    ) {
        parent::__construct('its body is longer than allowed');
    }

    public function named(string $request): TooLargeException
    {
        $limit = number_format($this->limit);
        $what = $this->length === null
            ? "with more than {$limit} bytes of body"
            : 'with a body of ' . number_format($this->length) . " bytes, more than the {$limit} allowed";
        return new TooLargeException(
            "{$request} was answered {$what}. Give request() a larger maxBytes: to take it.",
            $this->limit,
            $this,
        );
    }
}
