<?php

declare(strict_types=1);

namespace Crab;

use Crab\Contracts\Development as DevelopmentContract;

/**
 * What plugins have asked `dev` to do; see Contracts\Development. It
 * only holds what was said: Server::watch() acts on it.
 */
final class Development implements DevelopmentContract
{
    /**
     * @var array<string, list<string>> The extensions watched under each path.
     */
    private array $watched = [];
    /**
     * @var array<string, \Closure(list<string>|null): bool>
     */
    private array $steps = [];
    /**
     * @var array<string, \Closure(): void>
     */
    private array $listeners = [];
    /**
     * @var array<string, array{string, (\Closure(): bool)|null}>
     */
    private array $processes = [];

    public function watch(string $path, string ...$extensions): void
    {
        if ($path === '' || $extensions === []) {
            throw new \InvalidArgumentException(
                'Development::watch() takes a path inside the application and the extensions to watch there: watch(\'resources\', \'psx\').',
            );
        }
        foreach ($extensions as $extension) {
            if (preg_match('/^[A-Za-z0-9]+$/', $extension) !== 1) {
                throw new \InvalidArgumentException(
                    "Development::watch() takes extensions without the dot, such as \"psx\", not \"{$extension}\".",
                );
            }
        }
        $this->watched[$path] = array_values(array_unique([...($this->watched[$path] ?? []), ...$extensions]));
    }

    public function prepare(string $id, \Closure $step): void
    {
        $this->steps[$id] = $step;
    }

    public function started(string $id, \Closure $listener): void
    {
        $this->listeners[$id] = $listener;
    }

    public function process(string $id, string $command, ?\Closure $ready = null): void
    {
        if (trim($command) === '') {
            throw new \InvalidArgumentException("The development process \"{$id}\" has no command to run.");
        }
        $this->processes[$id] = [$command, $ready];
    }

    /**
     * @return array<string, list<string>>
     */
    public function watched(): array
    {
        return $this->watched;
    }

    /**
     * @return array<string, \Closure(list<string>|null): bool>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * @return array<string, \Closure(): void>
     */
    public function listeners(): array
    {
        return $this->listeners;
    }

    /**
     * @return array<string, array{string, (\Closure(): bool)|null}>
     */
    public function processes(): array
    {
        return $this->processes;
    }
}
