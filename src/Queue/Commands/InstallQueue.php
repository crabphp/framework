<?php

declare(strict_types=1);

namespace Crab\Queue\Commands;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Database\Migrator;

/**
 * Writes the migration for the jobs table into the application's own
 * migrations, where the application owns it.
 */
final readonly class InstallQueue implements Command
{
    public function __construct(private Migrator $migrator, private Config $config)
    {
    }

    public function description(): string
    {
        return "Write the migration for the jobs table into your migrations\n\nRun `migrate` afterwards to create the table.";
    }

    public function handle(Input $input, Output $output): int
    {
        $table = (string) $this->config->get('queue.table');
        $name = "create_{$table}_table";
        foreach (array_keys($this->migrator->status()) as $existing) {
            if (str_ends_with($existing, $name)) {
                $output->line("Your migrations already have {$existing}; nothing was written.");
                return 0;
            }
        }
        $file = $this->migrator->make($name);
        file_put_contents($file, self::migration($table));
        $output->line("Created {$file}");
        $output->line('Run `migrate` to create the table, then `queue:work` to run jobs.');
        return 0;
    }

    private static function migration(string $table): string
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
                        \$table->string('queue');
                        // The job's class, and the arguments of its handle() as JSON.
                        \$table->string('job');
                        \$table->text('payload');
                        \$table->integer('attempts')->default(0);
                        // Unix timestamps: when it may run, when a worker took it, when it was given up on.
                        \$table->bigInteger('available_at');
                        \$table->bigInteger('reserved_at')->nullable();
                        \$table->bigInteger('failed_at')->nullable();
                        \$table->text('error')->nullable();
                        \$table->bigInteger('created_at');
                        \$table->index(['queue', 'available_at']);
                    });
                }

                public function down(Schema \$schema): void
                {
                    \$schema->drop('{$table}');
                }
            };

            PHP;
    }
}
