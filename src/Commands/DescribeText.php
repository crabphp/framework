<?php

declare(strict_types=1);

namespace Crab\Commands;

/**
 * Writes what Describe gathers as plain text, one thing to a line, with
 * each route's input in TypeScript notation and its limits by JSON
 * Schema's names: { email: string (format="email"); age?: int | null }.
 * Agents read this as well as JSON or TOML, in fewer tokens.
 *
 * @internal
 */
final class DescribeText
{
    private const LEGEND = '# The application, from `php cua describe`. Types are TypeScript notation: `name?:` may be omitted, `| null` may be null. Limits in parentheses are JSON Schema keywords and are inclusive.';
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
    private const LIMITS = [
        'format',
        'pattern',
        'minimum',
        'maximum',
        'minLength',
        'maxLength',
        'minItems',
        'maxItems',
    ];

    /**
     * @param array<string, mixed> $description By section, as Describe gathers it.
     */
    public static function write(array $description): string
    {
        $blocks = [self::LEGEND];
        foreach ($description as $section => $value) {
            $blocks[] = match ($section) {
                'app' => self::app($value),
                'routes' => self::routes((array) $value),
                'middleware' => "middleware\n" . self::indent($value),
                'commands' => self::commands($value),
                'schedule' => self::schedule($value),
                'listeners' => self::listeners((array) $value),
                'servers' => self::servers($value),
                'database' => self::database($value),
                'config' => self::config($value),
            };
        }
        return implode("\n\n", $blocks);
    }

    /**
     * @param array<string, mixed> $app
     */
    private static function app(array $app): string
    {
        return implode(', ', [
            'app ' . self::json($app['name']),
            'url ' . ($app['url'] ?? 'none'),
            'debug ' . ($app['debug'] ? 'on' : 'off'),
            'php ' . $app['php'],
            'framework ' . ($app['framework'] ?? 'unknown'),
            'path ' . ($app['path'] ?? 'none'),
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $routes
     */
    private static function routes(array $routes): string
    {
        $lines = ['routes'];
        foreach ($routes as $route => $described) {
            $line = "{$route} -> {$described['action']}";
            if (isset($described['name'])) {
                $line .= " name={$described['name']}";
            }
            if (isset($described['middleware'])) {
                $line .= ' via ' . implode(', ', $described['middleware']);
            }
            $lines[] = '  ' . $line;
            if (!array_key_exists('input', $described)) {
                // Said, so that it is not read as "not shown".
                $lines[] = '    takes nothing';
                continue;
            }
            $input = $described['input'];
            if ($input === null) {
                $lines[] = '    input not declared';
                continue;
            }
            foreach ($input['parameters'] ?? [] as $name => $schema) {
                $lines[] = "    {{$name}}: " . self::type($schema);
            }
            foreach (array_intersect_key($input, ['query' => true, 'body' => true]) as $part => $schema) {
                $lines[] = "    {$part} " . self::type($schema);
            }
            if (isset($input['user'])) {
                $lines[] = '    user: ' . $input['user']['class'] . ($input['user']['required'] ? '' : ' | null');
            }
        }
        return implode("\n", $lines);
    }

    /**
     * A JSON Schema in TypeScript notation, its limits after it.
     *
     * @param array<string, mixed> $schema
     */
    private static function type(array $schema): string
    {
        $types = (array) ($schema['type'] ?? []);
        $nullable = in_array('null', $types, true);
        $types = array_values(array_diff($types, ['null']));
        $type = match (true) {
            isset($schema['enum']) => implode(' | ', array_map(self::json(...), $schema['enum'])),
            isset($schema['properties']) => self::object($schema),
            $types === ['array'] && isset($schema['items']) => 'Array<' . self::type($schema['items']) . '>',
            $types === ['array', 'object'] => 'array',
            $types === [] => 'mixed',
            default => ['integer' => 'int', 'number' => 'float', 'boolean' => 'bool'][$types[0]] ?? $types[0],
        };
        $limits = [];
        foreach (array_intersect_key($schema, array_flip(self::LIMITS)) as $name => $limit) {
            $limits[] = "{$name}=" . self::json($limit);
        }
        foreach ($schema['x-rules'] ?? [] as $rule) {
            $limits[] = "rule={$rule}";
        }
        if (array_key_exists('default', $schema)) {
            $limits[] = 'default=' . self::json($schema['default']);
        }
        return $type . ($nullable ? ' | null' : '') . ($limits === [] ? '' : ' (' . implode(', ', $limits) . ')');
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function object(array $schema): string
    {
        $required = $schema['required'] ?? [];
        $fields = [];
        foreach ((array) $schema['properties'] as $name => $property) {
            $fields[] = $name . (in_array($name, $required, true) ? '' : '?') . ': ' . self::type((array) $property);
        }
        return $fields === [] ? '{}' : '{ ' . implode('; ', $fields) . ' }';
    }

    /**
     * @param array<string, string> $commands
     */
    private static function commands(array $commands): string
    {
        $lines = ['commands'];
        foreach ($commands as $name => $description) {
            $lines[] = "  {$name}: {$description}";
        }
        return implode("\n", $lines);
    }

    /**
     * @param list<array{task: string, when: string, one_server: bool}> $tasks
     */
    private static function schedule(array $tasks): string
    {
        $lines = ['schedule'];
        foreach ($tasks as $task) {
            $lines[] = "  {$task['when']}: {$task['task']}" . ($task['one_server'] ? ', on one server' : '');
        }
        if ($tasks === []) {
            $lines[] = '  nothing';
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<string, list<string>> $listeners
     */
    private static function listeners(array $listeners): string
    {
        $lines = ['listeners'];
        foreach ($listeners as $event => $heard) {
            $lines[] = "  {$event}: " . implode(', ', $heard);
        }
        if ($listeners === []) {
            $lines[] = '  none';
        }
        return implode("\n", $lines);
    }

    /**
     * @param list<array{id: string, runs: string, on: bool}> $servers
     */
    private static function servers(array $servers): string
    {
        $lines = ['servers'];
        foreach ($servers as $server) {
            $lines[] = "  {$server['id']} runs {$server['runs']}, " . ($server['on'] ? 'on' : 'off');
        }
        return implode("\n", $lines);
    }

    /**
     * @param array{default: string|null, connections: array<string, array<string, mixed>>|\stdClass} $database
     */
    private static function database(array $database): string
    {
        $lines = ['database, default ' . ($database['default'] ?? 'none')];
        foreach ((array) $database['connections'] as $name => $connection) {
            $line = "  {$name} " . ($connection['driver'] ?? 'unknown');
            if ($connection['migrations'] !== null) {
                $line .=
                    ", migrations in {$connection['migrations']}: "
                    . ($connection['files'] === [] ? 'none' : implode(', ', $connection['files']));
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * One line to a section: "server address=... workers=1".
     *
     * @param array<mixed> $config
     */
    private static function config(array $config): string
    {
        $lines = ['config'];
        foreach ($config as $section => $values) {
            $lines[] =
                "  {$section} "
                . (
                    is_array($values) && $values !== [] && !array_is_list($values)
                        ? implode(' ', self::flatten($values))
                        : '= ' . self::json($values)
                );
        }
        return implode("\n", $lines);
    }

    /**
     * Nested settings as one "key.nested=value" each; a list stays whole.
     *
     * @param array<mixed> $values
     * @return list<string>
     */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $lines = [];
        foreach ($values as $key => $value) {
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $lines = [...$lines, ...self::flatten($value, "{$prefix}{$key}.")];
                continue;
            }
            $lines[] = "{$prefix}{$key}=" . self::json($value);
        }
        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    private static function indent(array $lines): string
    {
        return implode("\n", array_map(static fn(string $line): string => '  ' . $line, $lines));
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode($value, self::JSON);
    }
}
