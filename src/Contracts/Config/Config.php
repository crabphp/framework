<?php

declare(strict_types=1);

namespace Crab\Contracts\Config;

/**
 * Configuration, addressed with dotted keys such as "log.level". Its values
 * are fixed once the application is defined; providers may only add
 * defaults beneath them.
 */
interface Config
{
    /**
     * The value at a key, or the default when the key does not exist. A key
     * that exists with a null value yields null.
     */
    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    /**
     * Declares a section's settings and their default values, in one place.
     * A value the application has set wins; a setting it has not set reads
     * as its default from then on, through get() and all().
     *
     * @param string $section A top-level key such as "cache".
     * @param array<string, mixed> $values
     */
    public function defaults(string $section, array $values): void;

    /**
     * @return array<string, mixed>
     */
    public function all(): array;
}
