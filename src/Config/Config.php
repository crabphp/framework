<?php

declare(strict_types=1);

namespace Crab\Config;

use Crab\Contracts\Config\Config as ConfigContract;

/**
 * Read-only configuration, addressed with dotted keys such as "log.level".
 */
final readonly class Config implements ConfigContract
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

    public function all(): array
    {
        return $this->items;
    }
}
