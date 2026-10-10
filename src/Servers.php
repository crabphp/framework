<?php

declare(strict_types=1);

namespace Crab;

use Crab\Contracts\Servers as ServersContract;
use Crab\Contracts\Workers;

/**
 * The servers providers have registered for `serve` to run beside HTTP.
 */
final class Servers implements ServersContract
{
    /**
     * By id: what kind it is, what it runs, how long it is given to stop, and how the list names it.
     *
     * @var array<string, array{kind: 'groups'|'command'|'process', runs: \Closure|string, grace: float, label: string}>
     */
    private array $servers = [];

    public function add(string $id, \Closure $groups, float $grace = 5.0, string $runs = ''): void
    {
        $this->register($id, ['kind' => 'groups', 'runs' => $groups, 'grace' => $grace, 'label' => $runs]);
    }

    public function command(string $id, string $command): void
    {
        $this->register($id, ['kind' => 'command', 'runs' => self::checked($id, $command), 'grace' => 30.0, 'label' => 'php cua ' . trim($command)]);
    }

    public function process(string $id, string $command): void
    {
        $this->register($id, ['kind' => 'process', 'runs' => self::checked($id, $command), 'grace' => 30.0, 'label' => trim($command)]);
    }

    public function ids(): array
    {
        return array_keys($this->servers);
    }

    public function has(string $id): bool
    {
        return isset($this->servers[$id]);
    }

    /**
     * @return 'groups'|'command'|'process'
     */
    public function kind(string $id): string
    {
        return $this->servers[$id]['kind'];
    }

    /**
     * The command line of a server registered with command() or process().
     */
    public function line(string $id): string
    {
        return (string) $this->servers[$id]['runs'];
    }

    /**
     * How the list `serve` prints names what a server runs.
     */
    public function label(string $id): string
    {
        return $this->servers[$id]['label'];
    }

    /**
     * @return list<Workers> The groups of workers of a server registered with add().
     */
    public function groups(string $id): array
    {
        $groups = [];
        foreach (($this->servers[$id]['runs'])() as $workers) {
            if (!$workers instanceof Workers) {
                throw new \LogicException("The server \"{$id}\" must give a list of " . Workers::class . ', and gave ' . get_debug_type($workers) . '.');
            }
            $groups[] = $workers;
        }
        return $groups;
    }

    /**
     * @param list<string> $ids
     * @return float The longest any of the named servers asks for to finish in.
     */
    public function grace(array $ids): float
    {
        $grace = 0.0;
        foreach ($ids as $id) {
            $grace = max($grace, $this->servers[$id]['grace'] ?? 0.0);
        }
        return $grace;
    }

    /**
     * @param array{kind: 'groups'|'command'|'process', runs: \Closure|string, grace: float, label: string} $server
     */
    private function register(string $id, array $server): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $id) !== 1 || $id === 'http') {
            throw new \LogicException("\"{$id}\" cannot be a server's id: use lower-case letters, digits, \"-\" and \"_\", as in \"queue\"; \"http\" is the server's own.");
        }
        // Again under the same id: this one takes its place, where the first stood.
        $this->servers[$id] = $server;
    }

    private static function checked(string $id, string $command): string
    {
        if (trim($command) === '') {
            throw new \LogicException("The server \"{$id}\" was registered with no command to run.");
        }
        return trim($command);
    }
}
