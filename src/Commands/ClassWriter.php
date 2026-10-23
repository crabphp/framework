<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\App;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

/**
 * What every make: command that writes a class shares: it reads the class
 * name, --path and --namespace, refuses what would not be a valid new
 * class, and writes the file. A refusal is printed with the command's own
 * example.
 *
 * @internal
 */
final readonly class ClassWriter
{
    private const RESERVED = [
        'self',
        'parent',
        'static',
        'int',
        'float',
        'bool',
        'string',
        'true',
        'false',
        'null',
        'void',
        'iterable',
        'object',
        'mixed',
        'never',
    ];

    /**
     * @param string $command The command, such as "make:middleware".
     * @param string $example A class name for its messages, such as "RequireAdmin".
     * @param string $path The folder written in when --path is not given.
     * @param string $namespace The namespace of that folder.
     * @param list<string> $imports Lower-case names the generated class imports, which it cannot also be called.
     */
    public function __construct(
        private App $app,
        private string $command,
        private string $example,
        private string $path,
        private string $namespace,
        private array $imports = [],
    ) {}

    /**
     * Writes the class the input names with the source given for it.
     *
     * @param \Closure(string $namespace, string $name): string $source
     * @return array{string, string}|null The file and the class written, or null when refused.
     */
    public function write(Input $input, Output $output, string $kind, \Closure $source): ?array
    {
        foreach (['path', 'namespace'] as $option) {
            if ($input->option($option) === true) {
                throw new \InvalidArgumentException("--{$option} needs a value; use --{$option}=value.");
            }
        }
        $name = (string) $input->argument(0, '');
        $usage = "{$this->command} {$this->example}";
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name) !== 1) {
            $output->error("Give the {$kind} a class name: {$usage}");
            return null;
        }
        $namespace = (string) $input->option('namespace', $this->namespace);
        $hint = "{$usage} --namespace='{$this->namespace}'";
        if (
            preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $namespace) !== 1
            || in_array(strtolower($name), [...self::RESERVED, ...$this->imports], true)
        ) {
            $output->error("Give a PHP class name and namespace without reserved or imported names: {$hint}");
            return null;
        }
        try {
            // The parser knows PHP's keywords; the checks above cover reserved type names and import collisions.
            token_get_all("<?php namespace {$namespace}; final class {$name} {}", TOKEN_PARSE);
        } catch (\ParseError) {
            $output->error("Give a PHP class name and namespace: {$hint}");
            return null;
        }
        $path = (string) $input->option('path', $this->path);
        $pathHint = "--path={$this->path}";
        if ($path === '' || preg_match('/[\x00-\x1f\x7f]/', $path) === 1 || str_contains($path, '://')) {
            $output->error("--path must name a local folder; use {$pathHint}.");
            return null;
        }
        $directory = $this->app->path($path);
        $ancestor = $directory;
        while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
            $ancestor = dirname($ancestor);
        }
        if (!is_dir($ancestor)) {
            $output->error("{$ancestor} is not a folder. Choose a directory with {$pathHint}.");
            return null;
        }
        $file = "{$directory}/{$name}.php";
        if (is_file($file)) {
            $output->error("{$file} already exists. Choose a different class name or destination with {$pathHint}.");
            return null;
        }
        $class = $namespace . '\\' . $name;
        if (class_exists($class) || interface_exists($class) || trait_exists($class)) {
            $output->error("{$class} already exists. Choose another name: {$usage}");
            return null;
        }
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $output->error("The folder {$directory} cannot be created. Check permissions or choose {$pathHint}.");
            return null;
        }
        if (@file_put_contents($file, $source($namespace, $name)) === false) {
            $output->error("Could not write {$file}. Check the destination and its permissions.");
            return null;
        }
        $output->line("Created {$file}");
        return [$file, $class];
    }
}
