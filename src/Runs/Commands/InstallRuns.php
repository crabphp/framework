<?php

declare(strict_types=1);

namespace Crab\Runs\Commands;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Database\Migrators;

/**
 * Writes the migration for the runs' two tables into the application's
 * own migrations, where the application owns it.
 */
final readonly class InstallRuns implements Command
{
    public function __construct(
        private Migrators $migrators,
        private Config $config,
    ) {}

    public function description(): string
    {
        return "Write the migration for the runs and their steps into your migrations\n\nRun `migrate` afterwards to create the tables.";
    }

    public function handle(Input $input, Output $output): int
    {
        $database = $this->config->get('runs.database');
        $migrator = $this->migrators->migrator(is_string($database) && $database !== '' ? $database : null);
        $table = (string) $this->config->get('runs.table');
        $steps = (string) $this->config->get('runs.steps');
        $name = "create_{$table}_tables";
        foreach (array_keys($migrator->status()) as $existing) {
            if (!str_ends_with($existing, $name)) {
                continue;
            }

            $output->line("Your migrations already have {$existing}; nothing was written.");
            return 0;
        }
        $file = $migrator->make($name);
        if (@file_put_contents($file, self::migration($table, $steps)) === false) {
            $output->error(
                "Could not write {$file}. Check the destination and its permissions before running migrate.",
            );
            return 1;
        }
        $output->line("Created {$file}");
        $output->line('Run `migrate` to create the tables; queue workers then carry runs out.');
        return 0;
    }

    private static function migration(string $table, string $steps): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use Crab\Contracts\Database\Migration;
            use Crab\Contracts\Database\Schema;
            use Crab\Contracts\Database\Table;

            return new class implements Migration {
                public function up(Schema \$schema): void
                {
                    \$schema->create('{$table}', function (Table \$table) {
                        \$table->id();
                        // The run's class, and the arguments of its handle() as JSON.
                        \$table->string('run');
                        \$table->text('data');
                        \$table->string('status', 16);
                        \$table->text('result')->nullable();
                        \$table->text('error')->nullable();
                        \$table->integer('attempts')->default(0);
                        // Unix timestamps: until when a worker holds it, when its sleep ends.
                        \$table->bigInteger('lease_until')->nullable();
                        \$table->bigInteger('wake_at')->nullable();
                        \$table->bigInteger('created_at');
                        \$table->bigInteger('updated_at');
                        \$table->index(['status', 'id']);
                    });
                    \$schema->create('{$steps}', function (Table \$table) {
                        \$table->id();
                        \$table->bigInteger('run_id');
                        // A step's, a sleep's or a signal's name, unique in its run.
                        \$table->string('name', 191);
                        \$table->string('kind', 8);
                        \$table->text('result')->nullable();
                        \$table->bigInteger('wake_at')->nullable();
                        \$table->bigInteger('created_at');
                        \$table->unique(['run_id', 'name']);
                    });
                }

                public function down(Schema \$schema): void
                {
                    \$schema->drop('{$steps}');
                    \$schema->drop('{$table}');
                }
            };

            PHP;
    }
}
