<?php

declare(strict_types=1);

namespace Crab\Database\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Database\Migrator;
use Crab\Database\DatabaseManager;

final readonly class Fresh implements Command
{
    public function __construct(
        private DatabaseManager $databases,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Drop every table and run all the migrations again

            For development: every row is lost, and no down() is called.

            Options:
              --database=name   Another database than the default
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $ran = $this->migrator($input)->fresh();
        $output->line('Dropped all tables.');
        foreach ($ran as $name) {
            $output->line("Migrated     {$name}");
        }
        if ($ran === []) {
            $output->line('Nothing to migrate.');
        }
        return 0;
    }

    /**
     * The migrator of the database named with --database, or of the default one.
     */
    private function migrator(Input $input): Migrator
    {
        $name = $input->option('database');
        if ($name === true) {
            throw new \InvalidArgumentException('--database needs a value; use --database=value.');
        }
        if ($name !== null && ($name === '' || !in_array($name, $this->databases->names(), true))) {
            throw new \InvalidArgumentException(
                "There is no database named \"{$name}\"; the configured ones are: "
                . implode(', ', $this->databases->names())
                . '. Use --database=name to choose one.',
            );
        }
        return $this->databases->migrator($name);
    }
}
