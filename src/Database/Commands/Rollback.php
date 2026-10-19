<?php

declare(strict_types=1);

namespace Crab\Database\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Database\Migrator;
use Crab\Database\DatabaseManager;

final readonly class Rollback implements Command
{
    public function __construct(
        private DatabaseManager $databases,
    ) {}

    public function description(): string
    {
        return "Take back the migrations that ran last\n\nOptions:\n  --database=name   Another database than the default";
    }

    public function handle(Input $input, Output $output): int
    {
        $undone = $this->migrator($input)->rollback();
        foreach ($undone as $name) {
            $output->line("Rolled back  {$name}");
        }
        if ($undone === []) {
            $output->line('Nothing to roll back.');
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
        return $this->databases->migrator(is_string($name) ? $name : null);
    }
}
