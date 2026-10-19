<?php

declare(strict_types=1);

namespace Crab\Database\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Database\DatabaseManager;

final readonly class MakeMigration implements Command
{
    public function __construct(
        private DatabaseManager $databases,
    ) {}

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
        $database = $this->database($input);
        $name = implode(' ', $input->arguments());
        if (preg_match('/[a-z0-9]/i', $name) !== 1) {
            $output->error('Give the migration a name: make:migration create_users_table');
            return 1;
        }
        $output->line('Created ' . $this->databases->migrator($database)->make($name));
        return 0;
    }

    /**
     * Validate the selector before the migration name, without resolving a connection.
     */
    private function database(Input $input): ?string
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
        return $name;
    }
}
