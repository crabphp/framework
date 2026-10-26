<?php

declare(strict_types=1);

namespace Crab\Contracts\Http;

/**
 * What went wrong with a request, before it is written as a response:
 * every error status the framework answers with, from a missing route to
 * a failed action, is one of these, and an ErrorRenderer writes it.
 */
final readonly class Problem
{
    /**
     * @param int $status The error status, 400 to 599.
     * @param string $message Safe to show to the client.
     * @param array<string, list<string>> $fields What is wrong with the request's body, by field.
     * @param array<string, list<string>> $query What is wrong with its query string, by field.
     * @param array<string, list<string>> $path What is wrong with the parameters of its path, by name.
     * @param \Throwable|null $cause The exception the request was answered for, when there was one; not for the client unless $debug.
     * @param bool $debug Whether app.debug is on and $cause may be described in full.
     */
    public function __construct(
        public int $status,
        public string $message,
        public array $fields = [],
        public array $query = [],
        public array $path = [],
        public ?\Throwable $cause = null,
        public bool $debug = false,
    ) {}
}
