<?php

declare(strict_types=1);

namespace Crab\Contracts\Database;

/**
 * One change to the database's tables, and how to take it back. A
 * migration file returns one:
 *
 *     return new class implements Migration {
 *         public function up(Schema $schema): void { ... }
 *         public function down(Schema $schema): void { ... }
 *     };
 */
interface Migration
{
    public function up(Schema $schema): void;

    /**
     * Undoes exactly what up() did.
     */
    public function down(Schema $schema): void;
}
