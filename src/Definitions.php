<?php

declare(strict_types=1);

namespace Crab;

use Crab\Contracts\Definition;
use Crab\Contracts\Definitions as DefinitionsContract;

/**
 * The declarations packages make beside the application's routes, and
 * the one place they are finished: once, after every plugin, the
 * application's setup and its route files.
 */
final class Definitions implements DefinitionsContract
{
    /**
     * @var list<Definition>
     */
    private array $definitions = [];
    private bool $finished = false;

    public function add(Definition $definition): void
    {
        if ($this->finished) {
            throw new \LogicException(
                "The declarations of {$definition->name()} were added after the application was defined. Add them in a plugin's register().",
            );
        }
        $this->definitions[] = $definition;
    }

    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * Finishes every definition, once; calling it again does nothing.
     */
    public function finish(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        foreach ($this->definitions as $definition) {
            $definition->finish();
        }
    }
}
