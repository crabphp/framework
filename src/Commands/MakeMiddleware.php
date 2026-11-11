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
              --namespace='App\Http'        The namespace of that folder
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $writer = new ClassWriter($this->app, 'make:middleware', 'RequireAdmin', 'app/Http', 'App\\Http', [
            // The generated class shares a namespace with these imported names.
            'handler',
            'middleware',
            'request',
            'responseinterface',
        ]);
        $written = $writer->write($input, $output, 'middleware', self::source(...));
        if ($written === null) {
            return 1;
        }
        $class = '\\' . $written[1];
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

            use Crab\Contracts\Http\Handler;
            use Crab\Contracts\Http\Middleware;
            use Crab\Contracts\Http\Request;
            use Psr\Http\Message\ResponseInterface;

            final readonly class {$name} implements Middleware
            {
                public function handle(Request \$request, Handler \$next): ResponseInterface
                {
                    // Before the request reaches the route. Return a response here to stop it.

                    \$response = \$next->handle(\$request);

                    // After the route has answered.

                    return \$response;
                }
            }

            PHP;
    }
}
