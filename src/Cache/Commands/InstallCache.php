<?php

declare(strict_types=1);

namespace Crab\Cache\Commands;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Database\Migrators;

/**
 * Writes the migration for the database store's table into the
 * application's own migrations, where the application owns it.
 */
final readonly class InstallCache implements Command
{
    public function __construct(private Migrators $migrators, private Config $config)
    {
    }

    public function description(): string
    {
        return "Write the migration for the cache table into your migrations\n\nOnly the \"database\" store needs it. Run `migrate` afterwards to create the table.";
    }

    public function handle(Input $input, Output $output): int
    {
        // Into the migrations of the database the table is kept in, which need not be the default one.
        $database = $this->config->get('cache.database');
        $migrator = $this->migrators->migrator(is_string($database) && $database !== '' ? $database : null);
        $table = (string) $this->config->get('cache.table');
        $name = "create_{$table}_table";
        foreach (array_keys($migrator->status()) as $existing) {
            if (str_ends_with($existing, $name)) {
                $output->line("Your migrations already have {$existing}; nothing was written.");
                return 0;
            }
        }
        $file = $migrator->make($name);
        file_put_contents($file, self::migration($table));
        $output->line("Created {$file}");
        $output->line('Run `migrate` to create the table, and set "cache.store" to "database".');
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
                        \$table->string('key')->unique();
                        // The entry, as base64 text.
                        \$table->text('value');
                        // A Unix timestamp; an entry without one never expires.
                        \$table->bigInteger('expires_at')->nullable()->index();
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
