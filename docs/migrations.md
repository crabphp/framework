# Migrations

- [Introduction](#introduction)
- [Writing a migration](#writing-a-migration)
- [Running migrations](#running-migrations)
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

Set `database.migrations` to the folder that holds them; the skeleton sets it to `database/migrations`.

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

## Creating tables

### Columns

| Method | Column |
| --- | --- |
| `id()` | An auto-incrementing primary key named `id` |
| `foreignId('user_id')` | An unsigned big integer, for another table's id |
| `integer('age')`, `bigInteger('views')` | Whole numbers |
| `string('name')`, `string('code', 20)` | Text up to 255 characters, or the length given |
| `text('body')` | Long text |
| `boolean('active')` | True or false |
| `decimal('price', 10, 2)` | An exact number: digits in all, digits after the point |
| `float('ratio')` | An approximate number |
| `date('born_on')`, `dateTime('sent_at')` | A date; a date and time |
| `json('settings')` | JSON |
| `binary('data')` | Bytes |
| `timestamps()` | `created_at` and `updated_at`, both nullable |

Use `decimal` for money, never `float`.

### Column options

```php
$table->string('phone')->nullable();                 // may be empty
$table->integer('stock')->default(0);
$table->dateTime('created_at')->useCurrent();        // the time of insertion
$table->integer('quantity')->unsigned();
$table->string('email')->unique();
$table->string('status')->index();
```

A column must be given a value on insert unless it is `nullable()` or has a default.

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

The same file runs on both; the schema is written for the database in use. They differ in one way that matters:

- **On SQLite a migration that fails leaves nothing behind**, because it runs in a transaction.
- **MySQL commits each change to a table as it is made.** A migration that fails halfway has done the first half. Keep each migration small, one change to one table where you can, so that a failure leaves little to tidy.

SQLite checks foreign keys on the connections the provider opens.

## Several databases and packages

Each configured database has its own migrations folder and its own record of what ran. The commands take `--database=`:

```sh
php cua make:migration create_totals_table --database=reports
php cua migrate --database=reports
```

A package adds its own folder of migrations from its provider, through `Crab\Contracts\Database\Migrator::path()`.

The framework itself ships no migrations. A component that needs a table writes the migration into your folder with an `install` command, where you own it:

```sh
php cua tokens:install       # authentication tokens
php cua queue:install        # the queue's jobs
php cua cache:install        # the cache's database store
```
