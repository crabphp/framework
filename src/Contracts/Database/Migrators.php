<?php

declare(strict_types=1);

namespace Crab\Contracts\Database;

/**
 * The migrator of each configured database. A component that keeps its
 * table in a database other than the default asks for that database's
 * migrator here, so that its `install` command writes the migration
 * where that database's migrations are.
 */
interface Migrators
{
    /**
     * @param string|null $name The database's name in the configuration; the default one when null.
     */
    public function migrator(?string $name = null): Migrator;
}
