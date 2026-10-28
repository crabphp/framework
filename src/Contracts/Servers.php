<?php

declare(strict_types=1);

namespace Crab\Contracts;

/**
 * The servers `start` can run beside HTTP, each under an id: "queue",
 * "schedule", "chat", "vite". A provider registers one here, and `start`
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
 *
 * A server registered with `reloads: false` is kept across reloads: in
 * the background it runs in a server process of its own, which `reload`
 * leaves running on the code it started with, and only `restart` and
 * `stop` end; in the foreground SIGUSR1 leaves its workers as they are.
 * It is for work a deploy must not cut short, such as an agent in the
 * middle of a long task. Being held, it may listen on an address of its
 * own in the background too.
 *
 *     $servers->add('agent', static fn (): array => [new Workers('agent', $run)], grace: 30.0, reloads: false);
 */
interface Servers
{
    /**
     * Registers a server that joins `start` itself, as groups of its
     * workers. The groups are asked for when the server starts, not when
     * it is registered, so they may depend on what later providers and
     * the configuration say.
     *
     * @param \Closure(): list<Workers> $groups The groups of workers it runs.
     * @param float $grace Seconds its workers are given to finish what they have in hand when the server stops.
     * @param string $runs What it is, for the list `start` prints: "queue:work".
     * @param bool $reloads False to keep it running across reloads; see above.
     */
    public function add(
        string $id,
        \Closure $groups,
        float $grace = 5.0,
        string $runs = '',
        bool $reloads = true,
    ): void;

    /**
     * Registers one of the application's own commands, run as it would
     * be from the command line, in a process of its own: started with
     * the server, sent SIGTERM when it stops or reloads, and started
     * again if it ends.
     *
     * @param string $command The command and its options: "prices:stream --exchange=nyse".
     * @param bool $reloads False to keep it running across reloads; see above.
     */
    public function command(string $id, string $command, bool $reloads = true): void;

    /**
     * Registers any program, run through the shell in a process of its
     * own, as command() is: "npm run dev".
     */
    public function process(string $id, string $command, bool $reloads = true): void;

    /**
     * @return list<string> The ids registered, in the order first registered.
     */
    public function ids(): array;

    public function has(string $id): bool;
}
