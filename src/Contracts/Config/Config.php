<?php

declare(strict_types=1);

namespace Crab\Contracts\Config;

/**
 * Read-only configuration, addressed with dotted keys such as "log.level".
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
     * @return array<string, mixed>
     */
    public function all(): array;
}
