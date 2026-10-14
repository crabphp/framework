<?php

declare(strict_types=1);

namespace Crab\Contracts;

/**
 * The servers `serve` can run beside HTTP, each under an id: "queue",
 * "schedule", "chat", "vite". A provider registers one here, and `serve`
 * runs the ones "server.with" names, under the one parent: started,
 * stopped and reloaded with the HTTP workers.
 *
 *     $servers = $container->get(Servers::class);
 *     $servers->command('reverb', 'chat:serve --debug');     // one of the application's commands
 *     $servers->process('vite', 'npm run dev');              // any program
 *     $servers->add('chat', static fn (): array => [         // workers of the server's own
 *         new Workers('chat', workers: 2, setup: static function (Crab $group): void {
 *             $group->listen('tcp://0.0.0.0:8081', new WebSocketServer(...));
 *         }),
 *     ]);
 *
 * Registering an id again takes the place of what was registered under
 * it, so an application can say what "queue" runs: its provider comes
 * after the framework's.
 */
interface Servers
{
    /**
     * Registers a server that joins `serve` itself, as groups of its
     * workers. The groups are asked for when the server starts, not when
     * it is registered, so they may depend on what later providers and
     * the configuration say.
     *
     * @param \Closure(): list<Workers> $groups The groups of workers it runs.
     * @param float $grace Seconds its workers are given to finish what they have in hand when the server stops.
     * @param string $runs What it is, for the list `serve` prints: "queue:work".
     */
    public function add(string $id, \Closure $groups, float $grace = 5.0, string $runs = ''): void;

    /**
     * Registers one of the application's own commands, run as it would
     * be from the command line, in a process of its own: started with
     * the server, sent SIGTERM when it stops or reloads, and started
     * again if it ends.
     *
     * @param string $command The command and its options: "prices:stream --exchange=nyse".
     */
    public function command(string $id, string $command): void;

    /**
     * Registers any program, run through the shell in a process of its
     * own, as command() is: "npm run dev".
     */
    public function process(string $id, string $command): void;

    /**
     * @return list<string> The ids registered, in the order first registered.
     */
    public function ids(): array;

    public function has(string $id): bool;
}
