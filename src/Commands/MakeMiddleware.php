<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\App;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

final readonly class MakeMiddleware implements Command
{
    public function __construct(
        private App $app,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Write a new middleware class

            Arguments:
              name   The class: RequireAdmin, or AddApiVersion

            Options:
              --path=app/Http               The folder to write it in
              --namespace=App\Http          The namespace of that folder
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        foreach (['path', 'namespace'] as $option) {
            if ($input->option($option) === true) {
                throw new \InvalidArgumentException("--{$option} needs a value; use --{$option}=value.");
            }
        }
        $name = (string) $input->argument(0, '');
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name) !== 1) {
            $output->error('Give the middleware a class name: make:middleware RequireAdmin');
            return 1;
        }
        $namespace = (string) $input->option('namespace', 'App\\Http');
        $reserved = [
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
            // The generated class shares a namespace with these imported names.
            'middleware',
            'responseinterface',
            'serverrequestinterface',
            'requesthandlerinterface',
        ];
        if (
            preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $namespace) !== 1
            || in_array(strtolower($name), $reserved, true)
        ) {
            $output->error(
                'Give a PHP class name and namespace without reserved or imported names: make:middleware RequireAdmin --namespace=App\\Http',
            );
            return 1;
        }
        try {
            // The parser knows PHP's keywords; the checks above cover reserved type names and import collisions.
            token_get_all("<?php namespace {$namespace}; final class {$name} {}", TOKEN_PARSE);
        } catch (\ParseError) {
            $output->error('Give a PHP class name and namespace: make:middleware RequireAdmin --namespace=App\\Http');
            return 1;
        }
        $path = (string) $input->option('path', 'app/Http');
        if ($path === '' || preg_match('/[\x00-\x1f\x7f]/', $path) === 1 || str_contains($path, '://')) {
            $output->error('--path must name a local folder; use --path=app/Http.');
            return 1;
        }
        $directory = $this->app->path($path);
        $ancestor = $directory;
        while (!file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
            $ancestor = dirname($ancestor);
        }
        if (!is_dir($ancestor)) {
            $output->error("{$ancestor} is not a folder. Choose a directory with --path=app/Http.");
            return 1;
        }
        $file = "{$directory}/{$name}.php";
        if (is_file($file)) {
            $output->error(
                "{$file} already exists. Choose a different class name or destination with --path=app/Http.",
            );
            return 1;
        }
        $class = $namespace . '\\' . $name;
        if (class_exists($class) || interface_exists($class) || trait_exists($class)) {
            $output->error("{$class} already exists. Choose another name: make:middleware RequireAdmin");
            return 1;
        }
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $output->error("The folder {$directory} cannot be created. Check permissions or choose --path=app/Http.");
            return 1;
        }
        if (@file_put_contents($file, self::source($namespace, $name)) === false) {
            $output->error("Could not write {$file}. Check the destination and its permissions.");
            return 1;
        }
        $output->line("Created {$file}");
        $class = '\\' . $namespace . '\\' . $name;
        $output->line(
            "Use it around every request with ->withMiddleware([{$class}::class]) in app.php, or around some routes with ->middleware({$class}::class).",
        );
        return 0;
    }

    private static function source(string $namespace, string $name): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Crab\Contracts\Http\Middleware;
            use Psr\Http\Message\ResponseInterface;
            use Psr\Http\Message\ServerRequestInterface;
            use Psr\Http\Server\RequestHandlerInterface;

            final readonly class {$name} implements Middleware
            {
                public function process(ServerRequestInterface \$request, RequestHandlerInterface \$handler): ResponseInterface
                {
                    // Before the request reaches the route. Return a response here to stop it.

                    \$response = \$handler->handle(\$request);

                    // After the route has answered.

                    return \$response;
                }
            }

            PHP;
    }
}
