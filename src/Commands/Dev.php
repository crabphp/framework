<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\App;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Server;

/**
 * Serves while developing: `start` in a child process, started again
 * whenever a watched file changes. `start` itself never watches, so a
 * production server is not restarted by a deploy writing files.
 */
final readonly class Dev implements Command
{
    public function __construct(
        private App $app,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Serve the application while developing, restarting it when a file is saved

            Options:
              --address=tcp://127.0.0.1:8080   Where to listen; default from "server.address"
              --workers=1                      Worker processes; default from "server.workers"
              --single                         Serve in one process, without pcntl and posix
              --watch=app,config               What to watch instead of "server.watch"

            Ctrl+C stops it. In production, use `start`, which does not watch.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        Start::requireValues($input, ['address', 'workers', 'watch']);
        $address = Start::address($this->app, $input);
        $workers = Start::workers($this->app, $input);
        $paths = $this->watched($input);
        // The child says where it serves; this process only watches.
        return new Server($this->app)->watch(
            [
                ...Server::invocation('dev'),
                'start',
                "--address={$address}",
                "--workers={$workers}",
                ...($input->flag('single') ? ['--single'] : []),
            ],
            $paths,
            $output->line(...),
        );
    }

    /**
     * The paths of the --watch option, else those of "server.watch".
     *
     * @return list<string>
     */
    private function watched(Input $input): array
    {
        $option = $input->option('watch');
        if (is_string($option)) {
            $paths = explode(',', $option);
            foreach ($paths as $path) {
                if (
                    $path === ''
                    || preg_match('/[\x00-\x1f\x7f]/', $path) === 1
                    || str_contains($path, '://')
                    || !is_file($this->app->path($path)) && !is_dir($this->app->path($path))
                    || !is_readable($this->app->path($path))
                ) {
                    throw new \InvalidArgumentException(
                        '--watch must name readable local files or folders, separated by commas; use --watch=app,config.',
                    );
                }
            }
            return $paths;
        }
        $configured = $this->app->config->get('server.watch');
        if (
            !is_array($configured)
            || $configured === []
            || array_filter($configured, static fn(mixed $path): bool => !is_string($path) || $path === '') !== []
        ) {
            throw new \InvalidArgumentException(
                '"server.watch" lists what `dev` watches, as paths inside the application; use [\'app\', \'config\', \'routes\'].',
            );
        }
        return array_values($configured);
    }
}
