<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\App;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;

final readonly class MakeCommand implements Command
{
    public function __construct(
        private App $app,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Write a new command class, and register it in routes/console.php

            Arguments:
              name   The class: PruneUsers, or SendReport

            Options:
              --command=user:prune          The name it runs by; made from the class when left out
              --path=app/Console            The folder to write it in
              --namespace='App\Console'     The namespace of that folder

            Without routes/console.php, it says the line to add where your commands are declared.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $command = $input->option('command');
        if ($command === true) {
            throw new \InvalidArgumentException('--command needs a value; use --command=user:prune.');
        }
        $name = (string) $input->argument(0, '');
        $command = $command === null ? self::commandName($name) : (string) $command;
        if (preg_match('/^[a-z0-9][a-z0-9:_-]*$/D', $command) !== 1 || in_array($command, ['list', 'help'], true)) {
            $output->error(
                'Give the command a name of lower-case letters, digits, ":", "_" and "-", such as --command=user:prune.',
            );
            return 1;
        }
        if ($this->app->console->has($command)) {
            $output->error("A command named {$command} already exists. Choose another with --command=user:prune.");
            return 1;
        }
        $writer = new ClassWriter(
            $this->app,
            'make:command',
            'PruneUsers',
            'app/Console',
            'App\\Console',
            [
                // The generated class shares a namespace with these imported names.
                'command',
                'input',
                'output',
            ],
        );
        $written = $writer->write($input, $output, 'command', static fn(
            string $namespace,
            string $class,
        ): string => self::source($namespace, $class, $command));
        if ($written === null) {
            return 1;
        }
        $line = "\\Crab\\Command::add('{$command}', \\{$written[1]}::class);";
        $routes = $this->app->path('routes/console.php');
        if (is_file($routes) && is_writable($routes)) {
            $existing = (string) file_get_contents($routes);
            $separator = str_ends_with($existing, "\n") ? "\n" : "\n\n";
            if (@file_put_contents($routes, $separator . $line . "\n", FILE_APPEND) !== false) {
                $output->line("Registered it in {$routes}; run it with php cua {$command}");
                return 0;
            }
        }
        $output->line("Register it where your commands are declared, then run it with php cua {$command}:");
        $output->line('  ' . $line);
        return 0;
    }

    /**
     * "PruneUsers" runs as "user:prune": the area, in the singular, then
     * the action. A one-word class runs under "app:".
     */
    private static function commandName(string $class): string
    {
        $words = preg_split('/(?=[A-Z])/', $class, -1, PREG_SPLIT_NO_EMPTY) ?: [$class];
        $words = array_map(strtolower(...), $words);
        if (count($words) === 1) {
            return 'app:' . $words[0];
        }
        $action = array_shift($words);
        $area = implode('-', $words);
        if (strlen($area) > 3 && str_ends_with($area, 's') && !str_ends_with($area, 'ss')) {
            $area = substr($area, 0, -1);
        }
        return "{$area}:{$action}";
    }

    private static function source(string $namespace, string $name, string $command): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Crab\Contracts\Console\Command;
            use Crab\Contracts\Console\Input;
            use Crab\Contracts\Console\Output;

            /**
             * The constructor may ask for any service by type; the container makes the command.
             */
            final readonly class {$name} implements Command
            {
                public function description(): string
                {
                    // The first line is shown in the command list; all of it by php cua {$command} --help.
                    return 'Describe what {$command} does';
                }

                public function handle(Input \$input, Output \$output): int
                {
                    \$output->line('{$command} ran.');

                    return 0;
                }
            }

            PHP;
    }
}
