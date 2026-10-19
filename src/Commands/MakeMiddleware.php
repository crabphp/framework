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
        $namespace = trim((string) $input->option('namespace', 'App\\Http'), '\\');
        $directory = $this->app->path((string) $input->option('path', 'app/Http'));
        $file = "{$directory}/{$name}.php";
        if (is_file($file)) {
            $output->error("{$file} already exists.");
            return 1;
        }
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            $output->error("The folder {$directory} cannot be created.");
            return 1;
        }
        file_put_contents($file, self::source($namespace, $name));
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
