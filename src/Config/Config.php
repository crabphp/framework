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
     * What each setting takes, by section and key.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $rules = [];

    /**
     * @param array<string, mixed> $items
     */
    public function __construct(
        private array $items = [],
    ) {}

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
        $value = $this->find($key, $found);
        return $found ? $value : $default;
    }

    public function has(string $key): bool
    {
        $this->find($key, $found);
        return $found;
    }

    public function defaults(string $section, array $values, array $rules = []): void
    {
        if ($section === '' || str_contains($section, '.')) {
            throw new \InvalidArgumentException('Defaults are declared for a top-level section.');
        }
        foreach ($rules as $key => $rule) {
            Schema::assertValid("{$section}.{$key}", $rule);
            $earlier = $this->rules[$section][$key] ?? null;
            // Another provider offering one more choice adds to the ones there are.
            $this->rules[$section][$key] = isset($earlier['enum'], $rule['enum']) && array_keys($rule) === ['enum']
                ? ['enum' => array_values(array_unique([...$earlier['enum'], ...$rule['enum']], SORT_REGULAR))]
                + $earlier
                : $rule;
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

    public function problems(string ...$sections): array
    {
        $problems = [];
        $rules = $sections === [] ? $this->rules : array_intersect_key($this->rules, array_flip($sections));
        foreach ($rules as $section => $rules) {
            $values = $this->items[$section] ?? [];
            if (!is_array($values) || $values !== [] && array_is_list($values)) {
                $problems[] = "{$section} is not settings by name; it takes " . implode(', ', array_keys($rules)) . '.';
                continue;
            }
            foreach ($values as $key => $value) {
                $rule = $rules[$key] ?? $rules['*'] ?? null;
                if ($rule === null) {
                    $problems[] = Schema::unknown("{$section}.{$key}", (string) $key, array_keys($rules), $section);
                    continue;
                }
                array_push($problems, ...Schema::problems("{$section}.{$key}", $value, $rule));
            }
        }
        return $problems;
    }

    public function check(string ...$sections): void
    {
        $problems = $this->problems(...$sections);
        if ($problems !== []) {
            throw new SettingsException($problems);
        }
    }

    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * Walks a dotted key; a key that exists with a null value is found.
     */
    private function find(string $key, ?bool &$found): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                $found = false;
                return null;
            }
            $value = $value[$segment];
        }
        $found = true;
        return $value;
    }

    /**
     * Lays set values over defaults. Nested settings merge by name; a list
     * is one value and replaces the default list whole.
     */
    private static function over(array $defaults, array $set): array
    {
        foreach ($set as $key => $value) {
            $default = $defaults[$key] ?? null;
            $defaults[$key] = is_array($value)
            && is_array($default)
            && !array_is_list($value)
            && !array_is_list($default)
                ? self::over($default, $value)
                : $value;
        }
        return $defaults;
    }
}
