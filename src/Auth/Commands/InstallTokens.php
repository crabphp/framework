<?php

declare(strict_types=1);

namespace Crab\Auth\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Database\Migrator;

/**
 * Writes the migration for the tokens table into the application's own
 * migrations, where the application owns it: it can be read, changed and
 * rolled back like any other.
 */
final readonly class InstallTokens implements Command
{
    private const NAME = 'create_auth_tokens_table';

    public function __construct(
        private Migrator $migrator,
    ) {}

    public function description(): string
    {
        return "Write the migration for the tokens table into your migrations\n\nRun `migrate` afterwards to create the table.";
    }

    public function handle(Input $input, Output $output): int
    {
        foreach (array_keys($this->migrator->status()) as $existing) {
            if (!str_ends_with($existing, self::NAME)) {
                continue;
            }

            $output->line("Your migrations already have {$existing}; nothing was written.");
            return 0;
        }
        $file = $this->migrator->make(self::NAME);
        file_put_contents($file, self::migration());
        $output->line("Created {$file}");
        $output->line('Run `migrate` to create the table.');
        return 0;
    }

    private static function migration(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            use Crab\Contracts\Database\Migration;
            use Crab\Contracts\Database\Schema;
            use Crab\Contracts\Database\Table;

            return new class implements Migration {
                public function up(Schema $schema): void
                {
                    $schema->create('auth_tokens', function (Table $table) {
                        $table->id();
                        // The id of the user the token belongs to, as text so that any kind of id fits.
                        $table->string('owner_id', 64)->index();
                        $table->string('name')->default('');
                        // The SHA-256 of the token; the token itself is never stored.
                        $table->string('token_hash', 64)->unique();
                        $table->dateTime('expires_at')->nullable();
                        $table->dateTime('created_at');
                    });
                }

                public function down(Schema $schema): void
                {
                    $schema->drop('auth_tokens');
                }
            };

            PHP;
    }
}
