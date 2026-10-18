<?php

declare(strict_types=1);

namespace Crab\Database\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Database\Migrator;
use Crab\Database\DatabaseManager;

final readonly class Status implements Command
{
    public function __construct(
        private DatabaseManager $databases,
    ) {}

    public function description(): string
    {
        return "List the migrations and whether each has run\n\nOptions:\n  --database=name   Another database than the default";
    }

    public function handle(Input $input, Output $output): int
    {
        $status = $this->migrator($input)->status();
        if ($status === []) {
            $output->line('There are no migrations.');
            return 0;
        }
        $rows = [];
        foreach ($status as $name => $batch) {
            $rows[] = [$batch === null ? 'Pending' : "Ran ({$batch})", $name];
        }
        $output->table($rows);
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
