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
            'middleware',
            'responseinterface',
            'serverrequestinterface',
            'requesthandlerinterface',
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
