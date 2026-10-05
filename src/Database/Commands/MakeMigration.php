<?php

declare(strict_types=1);

namespace Crab\Database\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Database\Migrator;
use Crab\Database\DatabaseManager;

final readonly class MakeMigration implements Command
{
    public function __construct(private DatabaseManager $databases)
    {
    }

    public function description(): string
    {
        return <<<'TEXT'
            Write a new migration file

            Arguments:
              name   What it does: create_users_table, or "add phone to users"

            Options:
              --database=name   Write it for another database than the default

            A name of the form create_<table>_table starts with that table.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $name = implode(' ', $input->arguments());
        if ($name === '') {
            $output->error('Give the migration a name: make:migration create_users_table');
            return 1;
        }
        $output->line('Created ' . $this->migrator($input)->make($name));
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
