<?php

declare(strict_types=1);

namespace Crab\Contracts;

/**
 * What `dev` does for a plugin beside restarting the server when a PHP
 * file is saved: watch other files, prepare something before each server
 * it starts, and run a program for as long as it runs. A plugin says so
 * in register(); only `dev` reads it, so nothing here ever runs under
 * `start`.
 *
 *     $development = $container->get(Development::class);
 *     $development->watch('resources', 'psx');
 *     $development->prepare('psx', static fn (array $changed): bool => $compiler->compile());
 *     $development->process('vite', 'npx vite', ready: static fn (): bool => is_file($hot));
 */
interface Development
{
    /**
     * Watches a file or folder inside the application for files of these
     * extensions, beside "server.watch".
     *
     * @param string ...$extensions Without the dot: "psx", "tsx".
     */
    public function watch(string $path, string ...$extensions): void;

    /**
     * Work done before each server `dev` starts, the first included, such
     * as compiling: the server starts only once every step has returned.
     * A step returns true when the server must start anew to run what it
     * prepared, and false when what changed is nothing the server runs,
     * which leaves the running one alone. A step that throws keeps the
     * running server too, and its message is printed: a failed compile
     * never takes down what last worked.
     *
     * @param \Closure(list<string>|null): bool $step Given the files that changed, or null before the first server.
     */
    public function prepare(string $id, \Closure $step): void;

    /**
     * Told each time a server `dev` started accepts connections.
     *
     * @param \Closure(): void $listener
     */
    public function started(string $id, \Closure $listener): void;

    /**
     * A program `dev` runs through the shell for as long as it runs,
     * started before the first server and not restarted with it: a
     * bundler's own development server.
     *
     * @param \Closure(): bool|null $ready Whether the program is ready, asked until it is; `dev` starts the first server only then. Null when there is nothing to wait for.
     */
    public function process(string $id, string $command, ?\Closure $ready = null): void;
}
