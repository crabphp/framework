<?php

declare(strict_types=1);

namespace Crab\Config;

use Crab\Contracts\Config\Config as ConfigContract;

/**
 * Configuration read from arrays or a directory of PHP files.
 */
final class Config implements ConfigContract
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(private array $items = [])
    {
    }

    /**
     * Reads every PHP file of a directory; each returns an array and is
     * keyed by its file name, so app.php provides "app.*".
     */
    public static function load(string $directory): self
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Configuration directory {$directory} does not exist.");
        }
        $items = [];
        foreach (glob($directory . '/*.php') ?: [] as $file) {
            $values = require $file;
            if (!is_array($values)) {
                throw new \UnexpectedValueException("Configuration file {$file} must return an array.");
            }
            $items[basename($file, '.php')] = $values;
        }
        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function has(string $key): bool
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }
        return true;
    }

    public function defaults(string $section, array $values): void
    {
        if ($section === '' || str_contains($section, '.')) {
            throw new \InvalidArgumentException('Defaults are declared for a top-level section.');
        }
        $set = $this->items[$section] ?? [];
        // A section the application set to something other than an array is left alone.
        if (is_array($set)) {
            $this->items[$section] = self::over($values, $set);
        }
    }

    public function all(): array
    {
        return $this->items;
    }

    /**
     * Lays set values over defaults. Nested settings merge by name; a list
     * is one value and replaces the default list whole.
     */
    private static function over(array $defaults, array $set): array
    {
        foreach ($set as $key => $value) {
            $default = $defaults[$key] ?? null;
            $defaults[$key] = is_array($value) && is_array($default) && !array_is_list($value) && !array_is_list($default)
                ? self::over($default, $value)
                : $value;
        }
        return $defaults;
    }
}
