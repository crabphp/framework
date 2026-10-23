<?php

declare(strict_types=1);

namespace Crab\Config;

/**
 * Checks a setting against what it takes, written as the part of JSON
 * Schema settings need, and says what it takes in words. A string of
 * digits is an integer and a numeric string a number, as env() gives them.
 *
 * @internal
 */
final class Schema
{
    public const KEYWORDS = [
        'type',
        'enum',
        'minimum',
        'maximum',
        'exclusiveMinimum',
        'minLength',
        'pattern',
        'format',
        'items',
        'minItems',
        'properties',
        'additionalProperties',
        'examples',
        // Said after what the setting takes, when it does not fit.
        'description',
    ];

    /**
     * A setting named like this holds a secret, whose value is not repeated.
     */
    private const SECRET = '/(?:password|secret|token|private|(?:^|_)key)$/i';

    private const TYPES = ['string', 'integer', 'number', 'boolean', 'array', 'object', 'null'];

    /**
     * Refuses a rule that uses what this does not check, so that a typo in
     * a rule is found when it is declared and not taken for no rule.
     *
     * @param array<string, mixed> $schema
     */
    public static function assertValid(string $key, array $schema): void
    {
        $unknown = array_diff(array_keys($schema), self::KEYWORDS);
        if ($unknown !== []) {
            throw new \LogicException(
                "The rule for {$key} uses "
                . implode(', ', $unknown)
                . ', which settings do not check. They check '
                . implode(', ', self::KEYWORDS)
                . '.',
            );
        }
        foreach ((array) ($schema['type'] ?? []) as $type) {
            if (!in_array($type, self::TYPES, true)) {
                throw new \LogicException(
                    "The rule for {$key} has the type {$type}; the types are " . implode(', ', self::TYPES) . '.',
                );
            }
        }
        if (isset($schema['format']) && $schema['format'] !== 'timezone') {
            throw new \LogicException(
                "The rule for {$key} has the format {$schema['format']}; the one format is timezone.",
            );
        }
        if (isset($schema['items'])) {
            self::assertValid($key . '[]', $schema['items']);
        }
        foreach ($schema['properties'] ?? [] as $name => $property) {
            self::assertValid("{$key}.{$name}", $property);
        }
        if (is_array($schema['additionalProperties'] ?? null)) {
            self::assertValid($key . '.*', $schema['additionalProperties']);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @return list<string> One sentence for each thing wrong.
     */
    public static function problems(string $key, mixed $value, array $schema): array
    {
        if (!self::fits($value, $schema)) {
            return [self::sentence($key, $value, $schema)];
        }
        $problems = [];
        if (is_array($value) && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                array_push($problems, ...self::problems("{$key}.{$index}", $item, $schema['items']));
            }
        }
        if (is_array($value) && (isset($schema['properties']) || array_key_exists('additionalProperties', $schema))) {
            $properties = $schema['properties'] ?? [];
            foreach ($value as $name => $item) {
                $rule = $properties[$name] ?? $schema['additionalProperties'] ?? [];
                if ($rule === false) {
                    $problems[] = self::unknown("{$key}.{$name}", (string) $name, array_keys($properties), $key);
                    continue;
                }
                array_push($problems, ...self::problems("{$key}.{$name}", $item, $rule === true ? [] : $rule));
            }
        }
        return $problems;
    }

    /**
     * A key no rule names, with the ones there are.
     *
     * @param list<string|int> $known
     */
    public static function unknown(string $key, string $name, array $known, string $parent): string
    {
        $suggestion = '';
        foreach ($known as $candidate) {
            if (levenshtein($name, (string) $candidate) > 2) {
                continue;
            }
            $suggestion = " Did you mean {$parent}.{$candidate}?";
            break;
        }
        return (
            "{$key} is not a setting; {$parent} takes "
            . self::either(array_map('strval', $known), 'and')
            . '.'
            . $suggestion
        );
    }

    /**
     * What a rule takes, in words: "a whole number, at least 1".
     *
     * @param array<string, mixed> $schema
     */
    public static function describe(array $schema): string
    {
        if (isset($schema['enum'])) {
            $choices = array_map(self::json(...), $schema['enum']);
            return self::either($choices, 'or');
        }
        $types = (array) ($schema['type'] ?? []);
        $nullable = in_array('null', $types, true);
        $kinds = [];
        foreach (array_diff($types, ['null']) as $type) {
            $kinds[] = match ($type) {
                'integer' => 'a whole number' . self::range($schema),
                'number' => 'a number' . self::range($schema),
                'boolean' => 'true or false',
                'string' => self::string($schema),
                'array' => isset($schema['items'])
                    ? (($schema['minItems'] ?? 0) > 0 ? 'a non-empty list of ' : 'a list of ')
                        . self::plural(self::describe($schema['items']))
                    : 'a list',
                'object' => self::object($schema),
            };
        }
        if ($kinds === []) {
            $kinds[] = 'anything';
        }
        $words = self::either($kinds, 'or');
        if ($nullable) {
            // "at least 1, or null": null is not one more limit of the number.
            $words .= str_contains($words, ',') ? ', or null' : ' or null';
        }
        if (isset($schema['examples'])) {
            $words .= ', such as ' . self::json($schema['examples'][0]);
        }
        return $words;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function fits(mixed $value, array $schema): bool
    {
        if (isset($schema['enum'])) {
            return in_array($value, $schema['enum'], true);
        }
        $types = (array) ($schema['type'] ?? []);
        if ($types === []) {
            return true;
        }
        foreach ($types as $type) {
            if (self::fitsType($value, $type, $schema)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function fitsType(mixed $value, string $type, array $schema): bool
    {
        return match ($type) {
            'null' => $value === null,
            'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1'], true),
            'integer' => (
                is_int($value)
                || is_string($value)
                && preg_match('/^-?(0|[1-9][0-9]*)$/D', $value) === 1
                && filter_var($value, FILTER_VALIDATE_INT) !== false
            )
                && self::within((float) $value, $schema),
            'number' => (
                is_int($value)
                || is_float($value) && is_finite($value)
                || is_string($value)
                && is_numeric($value)
                && is_finite((float) $value)
            )
                && self::within((float) $value, $schema),
            'string' => is_string($value)
                && strlen($value) >= ($schema['minLength'] ?? 0)
                && (!isset($schema['pattern']) || preg_match('~' . $schema['pattern'] . '~', $value) === 1)
                && (
                    ($schema['format'] ?? null) !== 'timezone'
                    || in_array($value, \DateTimeZone::listIdentifiers(), true)
                    || $value === 'UTC'
                ),
            'array' => is_array($value) && array_is_list($value) && count($value) >= ($schema['minItems'] ?? 0),
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
        };
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function within(float $value, array $schema): bool
    {
        return (
            (!isset($schema['minimum']) || $value >= $schema['minimum'])
            && (!isset($schema['maximum']) || $value <= $schema['maximum'])
            && (!isset($schema['exclusiveMinimum']) || $value > $schema['exclusiveMinimum'])
        );
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function sentence(string $key, mixed $value, array $schema): string
    {
        $last = substr($key, (int) strrpos('.' . $key, '.'));
        // A secret's value is not repeated, whatever it is; that one is missing still is.
        $is =
            preg_match(self::SECRET, $last) === 1 && $value !== null && $value !== ''
                ? 'is not right'
                : 'is ' . self::shown($value);
        return (
            "{$key} {$is}; it takes "
            . self::describe($schema)
            . '.'
            . (isset($schema['description']) ? ' ' . $schema['description'] : '')
        );
    }

    private static function shown(mixed $value): string
    {
        return match (true) {
            $value === [] => 'empty',
            is_array($value) && array_is_list($value) => 'a list',
            is_array($value) => 'settings by name',
            is_object($value) => 'an object of ' . $value::class,
            is_float($value) && !is_finite($value) => (string) $value,
            // A password inside a URL, "redis://:secret@host" or "?password=secret", is not repeated.
            is_string($value) => self::json((string) preg_replace(
                ['~(://[^/@\s]*?:)[^@/\s]*@~', '~((?:pass(?:word)?|secret|token)=)[^&\s]*~i'],
                ['$1(hidden)@', '$1(hidden)'],
                $value,
            )),
            default => self::json($value),
        };
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function range(array $schema): string
    {
        $minimum = $schema['minimum'] ?? null;
        $maximum = $schema['maximum'] ?? null;
        return match (true) {
            isset($schema['exclusiveMinimum']) => ' greater than ' . self::json($schema['exclusiveMinimum']),
            $minimum !== null && $maximum !== null => ' from ' . self::json($minimum) . ' to ' . self::json($maximum),
            $minimum !== null => ', at least ' . self::json($minimum),
            $maximum !== null => ', at most ' . self::json($maximum),
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function string(array $schema): string
    {
        if (($schema['format'] ?? null) === 'timezone') {
            return 'a time zone';
        }
        $length = $schema['minLength'] ?? 0;
        return match (true) {
            $length > 1 => "a string of at least {$length} characters",
            $length === 1 => 'a non-empty string',
            default => 'a string',
        };
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function object(array $schema): string
    {
        if (isset($schema['properties'])) {
            return 'settings by name: ' . self::either(array_map('strval', array_keys($schema['properties'])), 'and');
        }
        $each = $schema['additionalProperties'] ?? true;
        return is_array($each) && $each !== [] ? 'names, each with ' . self::describe($each) : 'settings by name';
    }

    /**
     * "a whole number" as "whole numbers", for "a list of".
     */
    private static function plural(string $words): string
    {
        return (string) preg_replace(
            ['/^an? (non-empty )?string/', '/^a whole number/', '/^a number/'],
            ['$1strings', 'whole numbers', 'numbers'],
            $words,
        );
    }

    /**
     * @param list<string> $words
     */
    private static function either(array $words, string $last): string
    {
        $words = array_values($words);
        return count($words) < 2
            ? $words[0] ?? ''
            : implode(', ', array_slice($words, 0, -1)) . " {$last} " . end($words);
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}
