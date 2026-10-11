# Migrations

- [Introduction](#introduction)
- [Writing a migration](#writing-a-migration)
- [Running migrations](#running-migrations)
- [Deploying from several machines](#deploying-from-several-machines)
- [Creating tables](#creating-tables)
    - [Columns](#columns)
    - [Column options](#column-options)
    - [Indexes](#indexes)
    - [Foreign keys](#foreign-keys)
- [Changing tables](#changing-tables)
- [Moving data](#moving-data)
- [MySQL and SQLite](#mysql-and-sqlite)
- [Several databases and packages](#several-databases-and-packages)

## Introduction

A migration is a file that changes your tables and knows how to take the change back. Migrations live in your repository and run in order, so every machine, yours, a colleague's, production, arrives at the same tables by the same steps.

Set the connection's `migrations` to the folder that holds them; the skeleton sets it to `database/migrations`.

## Writing a migration

```sh
php cua make:migration create_posts_table
# Created database/migrations/2026_10_10_101500_create_posts_table.php
```

A name of the form `create_<table>_table` starts the file with that table. Any other name, such as `add_phone_to_users`, starts it empty.

```php
use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Schema;
use Crab\Contracts\Database\Table;

return new class implements Migration {
    public function up(Schema $schema): void
    {
        $schema->create('posts', function (Table $table) {
            $table->id();
            $table->foreignId('user_id')->references('users', onDelete: 'cascade');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('body')->nullable();
            $table->boolean('published')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'published']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('posts');
    }
};
```

`up()` makes the change and `down()` takes it back.

## Running migrations

```sh
php cua migrate                  # runs those that have not run
php cua migrate:status           # lists each and whether it has run
php cua migrate:rollback         # takes back the last run
```

Migrations run in the order of their file names. Each `migrate` is a batch, and `migrate:rollback` takes back the last batch, newest first.

Run `php cua migrate` as a step of every deploy, before the new code goes live.

> [!WARNING]
> Never edit a migration that has run anywhere but your own machine. Write a new one that makes the correction.

## Deploying from several machines

During a rolling deploy, several machines may run `php cua migrate` together. A database lock prevents them from applying the same migration twice. MySQL holds the lock for the command. SQLite locks each migration and checks its record again before making changes, so several commands may share the work.

`database.migration_lock_timeout` is the number of whole seconds to wait for a lock, `60` by default. Zero does not wait; fractional configuration values are cast to integers. Increase it when your migrations take longer:

```php
// config/database.php
return [
    'connections' => [
        'main' => [
            'dsn' => env('DB_DSN'),
            'migrations' => dirname(__DIR__) . '/database/migrations',
        ],
    ],
    'migration_lock_timeout' => 120,
];
```

If the wait expires, the command fails and tells you to inspect `php cua migrate:status`. Status and creating a migration file remain available while another command holds the lock. Status shows committed work, including each SQLite migration as it finishes. A rollback chooses the latest committed batch and skips migrations another rollback already took back.

MySQL uses a connection lock named `crab_migrations_` followed by the migrations table name. Applications using the same table name on one MySQL server share that lock. The command holds one pooled connection and gives it to the migration's `Schema`; a migration that also asks the application's `Database` service for a connection needs a pool of at least two, or it fails with “No database connection became free …; the pool holds 1.” If that session loses its lock, the command stops before the next migration or before returning, warns that a migration may be half applied, and tells you to inspect `migrate:status`.

SQLite commits each migration separately. Its writer transaction blocks other writes only for that migration, and its commit callbacks run before the next one starts. A failed migration rolls back its own changes; earlier committed migrations remain. A commit callback that fails reports its migration's name, but its database changes are already committed. Both databases release their locks after success or failure.

## Creating tables

### Columns

| Method | Column |
| --- | --- |
| `id()` | An auto-incrementing primary key named `id` |
| `foreignId('user_id')` | Another table's id: an unsigned big integer on MySQL, an integer on SQLite |
| `integer('age')`, `bigInteger('views')` | Whole numbers |
| `string('name')`, `string('code', 20)` | Text up to 255 characters, or the length given, on MySQL; SQLite does not enforce the length |
| `text('body')` | Long text |
| `boolean('active')` | True or false |
| `decimal('price', 10, 2)` | On MySQL, an exact number with total digits and digits after the point; SQLite ignores precision and scale and may store floating point |
| `float('ratio')` | An approximate number |
| `date('born_on')`, `dateTime('sent_at')` | A date; a date and time |
| `json('settings')` | JSON |
| `binary('data')` | Bytes |
| `timestamps()` | `created_at` and `updated_at`, both nullable |

Use `decimal` for money on MySQL; use integer minor units for exact money on both databases. SQLite does not enforce `unsigned()` either.

### Column options

```php
$table->string('phone')->nullable();                 // may be empty
$table->integer('stock')->default(0);
$table->dateTime('created_at')->useCurrent();        // the time of insertion
$table->integer('quantity')->unsigned();
$table->string('email')->unique();
$table->string('status')->index();
```

A non-generated column must be given a value on insert unless it is `nullable()` or has a default; `id()` is generated and may be omitted.

### Indexes

```php
$table->index('email');
$table->index(['user_id', 'created_at']);            // one index over two columns
$table->unique(['team_id', 'slug']);
$table->primary(['user_id', 'role_id']);             // in place of id(), for a join table
```

Add an index for every column you filter or join on in a query that runs often.

### Foreign keys

```php
$table->foreignId('user_id')->references('users');                          // users.id
$table->foreignId('team_id')->references('teams', onDelete: 'cascade');     // deleted with its team
$table->foreignId('author_id')->references('users', 'id', onDelete: 'set null')->nullable();
```

## Changing tables

`$schema->table()` changes a table that exists:

```php
public function up(Schema $schema): void
{
    $schema->table('users', function (Table $table) {
        $table->string('phone')->nullable();
        $table->index('phone');
        $table->renameColumn('name', 'full_name');
        $table->dropColumn('legacy_code');
    });
}

public function down(Schema $schema): void
{
    $schema->table('users', function (Table $table) {
        $table->dropIndex('phone');
        $table->dropColumn('phone');
        $table->renameColumn('full_name', 'name');
        $table->string('legacy_code')->nullable();
    });
}
```

| On the schema | |
| --- | --- |
| `create($table, $callback)` | A new table |
| `table($table, $callback)` | New columns and indexes, `renameColumn()`, `dropColumn()`, `dropIndex()`, `dropUnique()` |
| `drop($table)`, `dropIfExists($table)` | Remove a table |
| `rename($from, $to)` | Rename a table |
| `hasTable($table)`, `hasColumn($table, $column)` | Ask what is there |

> [!NOTE]
> A column's type cannot be changed in `table()`, because SQLite has no statement for it. Add a column of the new type, copy the values, and drop the old one.

A column added to a table that already has rows must be `nullable()` or have a default, since the existing rows need a value.

## Moving data

`$schema->connection()` is the database, for rows that must move with a change:

```php
public function up(Schema $schema): void
{
    $schema->table('users', function (Table $table) {
        $table->string('status')->default('active');
    });

    $schema->connection()->table('users')->where('banned', true)->update(['status' => 'banned']);
}
```

## MySQL and SQLite

The same file runs on both; the schema is written for the database in use. Besides column constraints, they differ in transactions:

- **On SQLite each migration runs in a transaction.** A failure before commit rolls back its changes; a failed commit callback leaves its already committed changes.
- **MySQL commits each change to a table as it is made.** A migration that fails halfway has done the first half. Keep each migration small, one change to one table where you can, so that a failure leaves little to tidy.

SQLite checks foreign keys on the connections the provider opens.

## Several databases and packages

Each configured database has its own migrations folder and its own record of what ran. The commands take `--database=`:

```sh
php cua make:migration create_totals_table --database=reports
php cua migrate --database=reports
```

A package adds its own folder of migrations from its provider, through `Crab\Contracts\Database\Migrator::path()`.

The framework itself ships no migrations. A component that needs a table writes the migration into your folder with an `install` command, where you own it. One that is told to keep its table in another database, with `queue.database` or `cache.database`, writes it into that database's folder; run `migrate --database=` for it.

```sh
php cua token:install       # authentication tokens
php cua queue:install        # the queue's jobs
php cua cache:install        # the cache's database store
```
