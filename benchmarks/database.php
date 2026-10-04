<?php

declare(strict_types=1);

/**
 * In-process cost of the database layer, without HTTP: each workload runs
 * through raw PDO and through Crab's Database, on the same connection
 * settings, so the difference is what the layer adds or saves.
 *
 *   pdo, prepare each time   prepare, bind, execute and fetch on every call
 *   pdo, prepared once       one statement reused: the least PDO can cost
 *   crab, $db                Database: borrows a pooled connection per call
 *   crab, held connection    one Connection kept for the run, as inside run()
 *
 * Each variant runs alone, on one open connection and a freshly filled
 * table, so no variant sees another's connections or rows. Writes always
 * change a value. It creates and drops its own table, bench_items. Times
 * are wall-clock, the best of the trials; the server shares this machine.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Crab\Contracts\Database\Connection;
use Crab\Database\Database;

$options = getopt('', ['dsn:', 'user:', 'password:', 'iterations:', 'writes:', 'trials:', 'rows:']);
$dsn = $options['dsn'] ?? null;
$iterations = filter_var($options['iterations'] ?? 20000, FILTER_VALIDATE_INT);
$writes = filter_var($options['writes'] ?? 2000, FILTER_VALIDATE_INT);
$trials = filter_var($options['trials'] ?? 5, FILTER_VALIDATE_INT);
$rows = filter_var($options['rows'] ?? 10000, FILTER_VALIDATE_INT);
if (!is_string($dsn) || $iterations < 1 || $writes < 1 || $trials < 1 || $rows < 100) {
    fwrite(STDERR, "Usage: php database.php --dsn=sqlite:/path/file.sqlite|mysql:host=127.0.0.1;dbname=name [--user=root] [--password=] [--iterations=20000] [--writes=2000] [--trials=5] [--rows=10000]\n");
    exit(2);
}
$user = $options['user'] ?? null;
$password = $options['password'] ?? null;
$mysql = str_starts_with($dsn, 'mysql:');

$connect = static function () use ($dsn, $user, $password): PDO {
    $pdo = new PDO($dsn, $user, $password);
    // The settings Crab's connection applies, so both sides run alike.
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
    return $pdo;
};

$selectOne = 'select id, name, value from bench_items where id = ?';
$selectTen = 'select id, name, value from bench_items where id between ? and ?';
$insert = 'insert into bench_items (name, value) values (?, ?)';
$update = 'update bench_items set value = ? where id = ?';

/**
 * Drops, creates and fills the table, so every variant starts alike.
 */
$reset = static function (PDO $pdo) use ($mysql, $rows, $insert): void {
    $pdo->exec('drop table if exists bench_items');
    $pdo->exec($mysql
        ? 'create table bench_items (id int auto_increment primary key, name varchar(64) not null, value int not null) engine=InnoDB'
        : 'create table bench_items (id integer primary key autoincrement, name text not null, value integer not null)');
    $pdo->beginTransaction();
    $fill = $pdo->prepare($insert);
    for ($index = 1; $index <= $rows; ++$index) {
        $fill->execute(["item {$index}", $index % 97]);
    }
    $pdo->commit();
};

$bind = static function (PDOStatement $statement, array $bindings): PDOStatement {
    foreach ($bindings as $position => $value) {
        $statement->bindValue($position + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->execute();
    return $statement;
};

// A value no write has used before, so that every write changes its row.
$tick = 1000;

/**
 * The five workloads through raw PDO. With $reuse, each statement is
 * prepared once and kept; without, it is prepared on every call.
 *
 * @return array<string, Closure(int): mixed>
 */
$throughPdo = static function (PDO $pdo, bool $reuse) use ($bind, $rows, &$tick, $selectOne, $selectTen, $insert, $update): array {
    $kept = [];
    $statement = static function (string $sql) use ($pdo, $reuse, &$kept): PDOStatement {
        return $reuse ? $kept[$sql] ??= $pdo->prepare($sql) : $pdo->prepare($sql);
    };
    return [
        'select one row by primary key' => static function (int $i) use ($statement, $bind, $selectOne, $rows) {
            $found = $bind($statement($selectOne), [$i % $rows + 1]);
            $row = $found->fetch();
            $found->closeCursor();
            return $row;
        },
        'select ten rows by range' => static function (int $i) use ($statement, $bind, $selectTen, $rows) {
            $first = $i % ($rows - 10) + 1;
            return $bind($statement($selectTen), [$first, $first + 9])->fetchAll();
        },
        'update one row' => static function (int $i) use ($statement, $bind, $update, $rows, &$tick) {
            return $bind($statement($update), [++$tick, $i % $rows + 1])->rowCount();
        },
        'insert one row' => static function (int $i) use ($statement, $bind, $insert, $pdo, &$tick) {
            $bind($statement($insert), ['new ' . ++$tick, $tick]);
            return $pdo->lastInsertId();
        },
        'transaction of two updates' => static function (int $i) use ($statement, $bind, $update, $pdo, $rows, &$tick) {
            $pdo->beginTransaction();
            $bind($statement($update), [++$tick, $i % $rows + 1]);
            $bind($statement($update), [++$tick, ($i + 1) % $rows + 1]);
            $pdo->commit();
        },
    ];
};

/**
 * The five workloads through a Crab connection, pooled or held.
 *
 * @return array<string, Closure(int): mixed>
 */
$throughCrab = static function (Connection $db) use ($rows, &$tick, $selectOne, $selectTen, $insert, $update): array {
    return [
        'select one row by primary key' => static fn (int $i) => $db->first($selectOne, [$i % $rows + 1]),
        'select ten rows by range' => static fn (int $i) => $db->select($selectTen, [$i % ($rows - 10) + 1, $i % ($rows - 10) + 10]),
        'update one row' => static function (int $i) use ($db, $update, $rows, &$tick) {
            return $db->execute($update, [++$tick, $i % $rows + 1]);
        },
        'insert one row' => static function (int $i) use ($db, $insert, &$tick) {
            return $db->insert($insert, ['new ' . ++$tick, $tick]);
        },
        'transaction of two updates' => static function (int $i) use ($db, $update, $rows, &$tick) {
            return $db->transaction(static function (Connection $tx) use ($update, $i, $rows, &$tick): void {
                $tx->execute($update, [++$tick, $i % $rows + 1]);
                $tx->execute($update, [++$tick, ($i + 1) % $rows + 1]);
            });
        },
    ];
};

/**
 * @param array<string, Closure(int): mixed> $workloads
 * @return array<string, array{microseconds: float, per_second: int}>
 */
$measure = static function (array $workloads) use ($iterations, $writes, $trials): array {
    $results = [];
    foreach ($workloads as $workload => $operation) {
        $count = str_starts_with($workload, 'select') ? $iterations : $writes;
        for ($index = 0; $index < min(500, $count); ++$index) {
            $operation($index);
        }
        $best = INF;
        for ($trial = 0; $trial < $trials; ++$trial) {
            $started = hrtime(true);
            for ($index = 0; $index < $count; ++$index) {
                $operation($index);
            }
            $best = min($best, (hrtime(true) - $started) / $count / 1000);
        }
        $results[$workload] = ['microseconds' => round($best, 2), 'per_second' => (int) round(1_000_000 / $best)];
    }
    return $results;
};

$byVariant = [];
foreach (['pdo, prepare each time' => false, 'pdo, prepared once' => true] as $variant => $reuse) {
    $pdo = $connect();
    $reset($pdo);
    $byVariant[$variant] = $measure($throughPdo($pdo, $reuse));
    $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    // Closed before the next variant opens its own.
    $pdo = null;
}

// One pooled connection, as a blocking worker has, so that only one is ever open.
$db = new Database($connect, max: 1);
$db->run(static fn (Connection $held) => null);
$setup = $connect();
$reset($setup);
$setup = null;
$byVariant['crab, $db'] = $measure($throughCrab($db));
$db->close();

$setup = $connect();
$reset($setup);
$setup = null;
$byVariant['crab, held connection'] = $db->run(static fn (Connection $held): array => $measure($throughCrab($held)));
$db->close();

$cleanup = $connect();
$cleanup->exec('drop table bench_items');

// Regroup as workload => variant, which is how the results are read.
$results = [];
foreach ($byVariant as $variant => $workloads) {
    foreach ($workloads as $workload => $result) {
        $results[$workload][$variant] = $result;
    }
}

echo json_encode([
    'database' => ($mysql ? 'mysql ' : 'sqlite ') . $version,
    'dsn' => preg_replace('/password=[^;]*/', 'password=***', $dsn),
    'rows' => $rows,
    'read_iterations' => $iterations,
    'write_iterations' => $writes,
    'trials' => $trials,
    'php' => PHP_VERSION,
    'jit' => function_exists('opcache_get_status') ? (opcache_get_status(false)['jit']['on'] ?? false) : false,
    'results' => $results,
    'note' => 'Wall-clock microseconds per operation, best trial. The database server runs on the same machine.',
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
