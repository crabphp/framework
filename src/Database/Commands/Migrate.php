<?php

declare(strict_types=1);

namespace Crab\Database\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Database\Migrator;
use Crab\Database\DatabaseManager;

final readonly class Migrate implements Command
{
    public function __construct(private DatabaseManager $databases)
    {
    }

    public function description(): string
    {
        return "Run the migrations that have not run yet\n\nOptions:\n  --database=name   Another database than the default";
    }

    public function handle(Input $input, Output $output): int
    {
        $ran = $this->migrator($input)->run();
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
        return $this->databases->migrator(is_string($name) ? $name : null);
    }
}
