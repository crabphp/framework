<?php

declare(strict_types=1);

namespace Crab\Tests\Database;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Query as QueryContract;
use Crab\Database\Database;
use Crab\Database\DatabaseException;
use Crab\Database\Grammar;
use Crab\Database\MySqlGrammar;
use Crab\Database\Query;
use Crab\Database\SqliteGrammar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs against SQLite always, and against MySQL too when
 * CRAB_TEST_MYSQL_DSN names a database the tests may fill.
 */
final class QueryTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/crab-query-' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        if (getenv('CRAB_TEST_MYSQL_DSN') !== false) {
            $this->pdo('mysql')->exec('drop table if exists posts, users');
        }
    }

    public static function drivers(): array
    {
        return getenv('CRAB_TEST_MYSQL_DSN') === false ? [['sqlite']] : [['sqlite'], ['mysql']];
    }

    public function testASelectIsWrittenInEachDialectWithItsValuesInOrder(): void
    {
        $build = function (Grammar $grammar): Query {
            $db = $this->database('sqlite');
            $admins = new Query($db, 'roles', $grammar);
            $admins->select('user_id')->where('name', 'admin');
            $query = new Query($db, 'users as u', $grammar);
            return $query
                ->select('u.id', 'name as label', 'u.*')
                ->selectRaw('age * ? as doubled', [2])
                ->distinct()
                ->join('posts', 'posts.user_id', '=', 'u.id')
                ->leftJoin('teams as t', static fn (QueryContract $on) => $on->whereColumn('t.id', 'u.team_id')->where('t.open', true))
                ->where('age', '>=', 18)
                ->where(static fn (QueryContract $group) => $group->where('name', 'like', 'A%')->orWhere('email', null))
                ->orWhere(['active' => true, 'votes' => 0])
                ->whereIn('u.id', $admins)
                ->whereNotIn('age', [1, 2])
                ->whereBetween('votes', 1, 9)
                ->whereRaw('length(name) > ?', [3])
                ->groupBy('u.id', 'name')
                ->having('label', '!=', 'x')
                ->havingRaw('count(*) > ?', [1])
                ->orderBy('name')
                ->orderByDesc('u.id')
                ->orderByRaw('length(name) desc')
                ->limit(10)
                ->offset(20)
                ->lockForUpdate();
        };
        $bindings = [2, true, 18, 'A%', true, 0, 'admin', 1, 2, 1, 9, 3, 'x', 1, 10, 20];

        $sqlite = $build(new SqliteGrammar());
        self::assertSame(
            'select distinct `u`.`id`, `name` as `label`, `u`.*, age * ? as doubled from `users` as `u`'
            . ' inner join `posts` on `posts`.`user_id` = `u`.`id`'
            . ' left join `teams` as `t` on `t`.`id` = `u`.`team_id` and `t`.`open` = ?'
            . ' where `age` >= ? and (`name` like ? or `email` is null) or (`active` = ? and `votes` = ?)'
            . ' and `u`.`id` in (select `user_id` from `roles` where `name` = ?) and `age` not in (?, ?)'
            . ' and `votes` between ? and ? and (length(name) > ?)'
            . ' group by `u`.`id`, `name` having `label` != ? and (count(*) > ?)'
            . ' order by `name` asc, `u`.`id` desc, length(name) desc limit ? offset ?',
            $sqlite->toSql(),
        );
        self::assertSame($bindings, $sqlite->bindings());

        $mysql = $build(new MySqlGrammar());
        self::assertStringStartsWith('select distinct `u`.`id`, `name` as `label`, `u`.*, age * ? as doubled from `users` as `u` inner join `posts`', $mysql->toSql());
        self::assertStringEndsWith('limit ? offset ? for update', $mysql->toSql());
        self::assertSame($bindings, $mysql->bindings());
    }

    public function testTheDialectsDifferWhereTheDatabasesDo(): void
    {
        $db = $this->database('sqlite');
        $rows = [['email' => 'a@x', 'name' => 'A'], ['email' => 'b@x', 'name' => 'B']];
        $written = [];
        foreach (['sqlite' => new SqliteGrammar(), 'mysql' => new MySqlGrammar()] as $name => $grammar) {
            $query = new Query($db, 'users', $grammar);
            $paged = new Query($db, 'users', $grammar);
            $written[$name] = [
                $grammar->upsert($query, $rows, ['email'], ['name'])->sql,
                $grammar->insertOrIgnore($query, $rows)->sql,
                $paged->offset(5)->toSql(),
            ];
            self::assertSame(['a@x', 'A', 'b@x', 'B'], $grammar->upsert($query, $rows, ['email'], ['name'])->bindings);
        }
        self::assertSame([
            'insert into `users` (`email`, `name`) values (?, ?), (?, ?) on conflict (`email`) do update set `name` = excluded.`name`',
            'insert or ignore into `users` (`email`, `name`) values (?, ?), (?, ?)',
            'select * from `users` limit -1 offset ?',
        ], $written['sqlite']);
        self::assertSame([
            'insert into `users` (`email`, `name`) values (?, ?), (?, ?) on duplicate key update `name` = values(`name`)',
            'insert ignore into `users` (`email`, `name`) values (?, ?), (?, ?)',
            'select * from `users` limit 18446744073709551615 offset ?',
        ], $written['mysql']);

        self::assertInstanceOf(MySqlGrammar::class, Grammar::for('mysql'));
        self::assertInstanceOf(SqliteGrammar::class, Grammar::for('sqlite'));
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('"pgsql"');
        Grammar::for('pgsql');
    }

    #[DataProvider('drivers')]
    public function testRowsAreInsertedReadUpdatedAndDeleted(string $driver): void
    {
        $db = $this->database($driver);
        self::assertSame($driver, $db->driver());
        $users = $db->table('users');

        self::assertSame(1, $users->insert(['name' => 'Ada', 'email' => 'ada@x', 'age' => 36, 'active' => true]));
        self::assertSame(2, $users->insertMany([
            ['name' => 'Grace', 'email' => 'grace@x', 'age' => 45, 'active' => true],
            // The columns may come in another order.
            ['email' => 'linus@x', 'age' => null, 'active' => false, 'name' => 'Linus'],
        ]));
        self::assertSame(0, $users->insertMany([]));

        self::assertSame(['Ada', 'Grace', 'Linus'], array_column($users->get(), 'name'), 'Running a query leaves it as it was.');
        self::assertSame(['id' => 2, 'name' => 'Grace'], $db->table('users')->select('id', 'name')->where('age', '>', 40)->first());
        self::assertNull($db->table('users')->where('age', '>', 99)->first());
        self::assertSame('Linus', $users->find(3)['name']);
        self::assertSame('Ada', $users->find('ada@x', 'email')['name']);
        self::assertNull($users->find(99));
        self::assertSame('grace@x', $db->table('users')->where('name', 'Grace')->value('email'));
        self::assertNull($db->table('users')->where('name', 'nobody')->value('email'));
        self::assertSame(['ada@x', 'grace@x', 'linus@x'], $db->table('users')->orderBy('id')->pluck('email'));
        self::assertSame([1 => 'Ada', 2 => 'Grace'], $db->table('users')->where('active', true)->pluck('users.name as who', 'users.id'));

        self::assertSame(1, $db->table('users')->where('id', 1)->update(['age' => 37, 'name' => 'Ada L.']));
        self::assertSame(0, $db->table('users')->where('id', 99)->update(['age' => 1]));
        self::assertSame(['Ada L.', 37], [$users->find(1)['name'], $users->find(1)['age']]);

        self::assertSame(2, $db->table('users')->whereIn('id', [1, 2])->increment('votes'));
        self::assertSame(1, $db->table('users')->where('id', 1)->increment('votes', 4, ['active' => false]));
        self::assertSame(1, $db->table('users')->where('id', 2)->decrement('votes', 3));
        self::assertSame([1 => 5, 2 => -2, 3 => 0], $db->table('users')->pluck('votes', 'id'));
        self::assertSame(0, $users->find(1)['active']);

        self::assertSame(1, $db->table('users')->where('name', 'Linus')->delete());
        self::assertSame(2, $users->count());
        self::assertSame(2, $users->delete(), 'With no where, every row.');
        self::assertFalse($users->exists());
    }

    #[DataProvider('drivers')]
    public function testConditionsChooseTheRows(string $driver): void
    {
        $db = $this->seeded($driver);
        $names = static fn (QueryContract $query): array => $query->orderBy('id')->pluck('name');

        self::assertSame(['Ada'], $names($db->table('users')->where('name', 'Ada')));
        self::assertSame(['Grace', 'Linus', 'Margaret'], $names($db->table('users')->where('name', '!=', 'Ada')));
        self::assertSame(['Grace', 'Margaret'], $names($db->table('users')->where('age', '>', 40)));
        self::assertSame(['Ada', 'Grace'], $names($db->table('users')->where('name', 'LIKE', '%a%')->where('age', '<=', 45)));
        self::assertSame(['Linus'], $names($db->table('users')->where('age', null)));
        self::assertSame(['Ada', 'Grace', 'Margaret'], $names($db->table('users')->where('age', '!=', null)));
        self::assertSame(['Ada'], $names($db->table('users')->where(['active' => true, 'age' => 36])));
        self::assertSame(['Ada', 'Linus'], $names($db->table('users')->where('name', 'Ada')->orWhere('name', 'Linus')));
        self::assertSame(['Grace'], $names($db->table('users')->where('active', true)->where(
            static fn (QueryContract $group) => $group->where('age', '>', 80)->orWhere('name', 'Grace'),
        )));
        self::assertSame(['Ada', 'Margaret'], $names($db->table('users')->where('name', 'Ada')->orWhere(
            static fn (QueryContract $group) => $group->where('active', false)->where('age', '>', 80),
        )));
        self::assertSame(['Ada'], $names($db->table('users')->where('name', 'Ada')->where(static fn () => null)), 'An empty group adds nothing.');

        self::assertSame(['Ada', 'Linus'], $names($db->table('users')->whereIn('id', [1, 3])));
        self::assertSame(['Grace', 'Margaret'], $names($db->table('users')->whereNotIn('id', [1, 3])));
        self::assertSame([], $names($db->table('users')->whereIn('id', [])));
        self::assertSame(['Ada', 'Grace', 'Linus', 'Margaret'], $names($db->table('users')->whereNotIn('id', [])));
        self::assertSame(['Ada', 'Grace'], $names($db->table('users')->where('id', 1)->orWhereIn('id', [2])));
        self::assertSame(['Ada', 'Linus'], $names($db->table('users')->where('id', 1)->orWhereNotIn('id', [2, 4])));
        $authors = $db->table('posts')->select('user_id')->where('title', 'like', '%engine%');
        self::assertSame(['Ada', 'Grace'], $names($db->table('users')->whereIn('id', $authors)));
        self::assertSame(['Linus', 'Margaret'], $names($db->table('users')->whereNotIn('id', $authors)));

        self::assertSame(['Linus'], $names($db->table('users')->whereNull('age')));
        self::assertSame(['Ada', 'Grace', 'Margaret'], $names($db->table('users')->whereNotNull('age')));
        self::assertSame(['Ada', 'Linus'], $names($db->table('users')->where('id', 1)->orWhereNull('age')));
        self::assertSame(['Ada', 'Grace', 'Margaret'], $names($db->table('users')->where('id', 1)->orWhereNotNull('age')));
        self::assertSame(['Ada', 'Grace'], $names($db->table('users')->whereBetween('age', 36, 45)));
        self::assertSame(['Margaret'], $names($db->table('users')->whereNotBetween('age', 36, 45)));
        self::assertSame(['Ada', 'Margaret'], $names($db->table('users')->where('id', 4)->orWhereBetween('age', 30, 40)));
        self::assertSame(['Ada', 'Margaret'], $names($db->table('users')->where('id', 1)->orWhereNotBetween('age', 36, 45)));
        self::assertSame(['Ada'], $names($db->table('users')->whereColumn('votes', 'id')));
        self::assertSame(['Grace', 'Linus', 'Margaret'], $names($db->table('users')->whereColumn('votes', '<', 'id')));
        self::assertSame(['Ada', 'Margaret'], $names($db->table('users')->where('id', 4)->orWhereColumn('votes', '=', 'id')));
        self::assertSame(['Margaret'], $names($db->table('users')->whereRaw('length(name) > ?', [5])));
        self::assertSame(['Ada', 'Margaret'], $names($db->table('users')->where('id', 1)->orWhereRaw('length(name) > ? or id = ?', [5, 99])));

        $search = 'gra';
        $found = $db->table('users')
            ->when($search !== '', static fn (QueryContract $query) => $query->where('name', 'like', "%{$search}%"))
            ->when(false, static fn (QueryContract $query) => $query->where('id', 0), static fn (QueryContract $query) => $query->where('active', true));
        self::assertSame(['Grace'], $names($found));
    }

    #[DataProvider('drivers')]
    public function testJoinsGroupsOrderAndPages(string $driver): void
    {
        $db = $this->seeded($driver);

        $titles = $db->table('posts')
            ->join('users', 'users.id', 'posts.user_id')
            ->select('users.name', 'posts.title')
            ->where('users.active', true)
            ->orderBy('posts.id')
            ->get();
        self::assertSame([
            ['name' => 'Ada', 'title' => 'Notes on the engine'],
            ['name' => 'Ada', 'title' => 'Numbers'],
            ['name' => 'Grace', 'title' => 'An engine compiles'],
        ], $titles);

        $counts = $db->table('users as u')
            ->leftJoin('posts as p', static fn (QueryContract $on) => $on->whereColumn('p.user_id', '=', 'u.id')->where('p.title', '!=', 'Numbers'))
            ->select('u.name')
            ->selectRaw('count(p.id) as posts')
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('posts')
            ->orderBy('u.name')
            ->get();
        self::assertSame([['Ada', 1], ['Grace', 1], ['Linus', 0], ['Margaret', 0]], array_map(static fn (array $row): array => [$row['name'], (int) $row['posts']], $counts));

        $busy = $db->table('posts')->select('user_id')->selectRaw('count(*) as total')->groupBy('user_id')->having('total', '>', 1)->get();
        self::assertSame([[1, 2]], array_map(static fn (array $row): array => [$row['user_id'], (int) $row['total']], $busy));
        $busy = $db->table('posts')->select('user_id')->groupBy('user_id')->havingRaw('count(*) = ?', [1])->pluck('user_id');
        self::assertSame([2], $busy);

        self::assertSame([1, 0], array_map(intval(...), $db->table('users')->select('active')->distinct()->orderByDesc('active')->pluck('active')));
        self::assertSame(['Margaret', 'Grace', 'Ada', 'Linus'], $db->table('users')->orderByRaw('age is null, age desc')->pluck('name'));
        self::assertSame(['Margaret', 'Linus'], $db->table('users')->latest('id')->limit(2)->pluck('name'));
        self::assertSame(['Ada', 'Grace'], $db->table('users')->oldest('id')->limit(2)->pluck('name'));
        self::assertSame(['Linus', 'Margaret'], $db->table('users')->orderBy('id')->offset(2)->pluck('name'));
        self::assertSame(['Linus'], $db->table('users')->orderBy('id')->forPage(3, 1)->pluck('name'));
        self::assertSame(['Grace', 'Linus'], $db->table('users')->orderBy('id')->forPage(1, 3)->offset(1)->limit(2)->pluck('name'));
        self::assertSame([], $db->table('users')->orderBy('id')->forPage(9)->get());
    }

    #[DataProvider('drivers')]
    public function testAggregatesCountWhatTheQueryWouldReturn(string $driver): void
    {
        $db = $this->seeded($driver);
        $users = $db->table('users');

        self::assertSame(4, $users->count());
        self::assertSame(3, $users->count('age'), 'Null is not counted.');
        self::assertSame(2, $db->table('users')->where('active', true)->orderBy('name')->count());
        self::assertSame(171, $users->sum('age'));
        self::assertSame(0, $db->table('users')->where('id', 99)->sum('age'));
        self::assertSame(57.0, $users->avg('age'));
        self::assertNull($db->table('users')->where('id', 99)->avg('age'));
        self::assertSame([36, 90], [$users->min('age'), $users->max('age')]);
        self::assertNull($db->table('users')->where('id', 99)->max('age'));
        self::assertSame(['Ada', 'Margaret'], [$users->min('name'), $users->max('name')]);
        self::assertTrue($db->table('users')->where('name', 'Ada')->exists());
        self::assertFalse($db->table('users')->where('name', 'nobody')->exists());

        self::assertSame(2, $db->table('users')->select('active')->distinct()->count());
        self::assertSame(2, $db->table('posts')->select('user_id')->groupBy('user_id')->count(), 'Groups are counted, not the rows in them.');
        self::assertSame(1, $db->table('posts')->select('user_id')->groupBy('user_id')->havingRaw('count(*) > 1')->count());
        self::assertSame(2, $db->table('users')->orderBy('id')->limit(2)->count());
        self::assertSame(81, $db->table('users')->orderBy('id')->limit(2)->sum('age'));
        self::assertSame(1, $db->table('users')->orderBy('id')->offset(3)->count());
        self::assertSame(3, $db->table('posts')->join('users', 'users.id', 'posts.user_id')->count());
    }

    #[DataProvider('drivers')]
    public function testUpsertAndInsertOrIgnoreLeanOnTheUniqueKey(string $driver): void
    {
        $db = $this->seeded($driver);
        $users = $db->table('users');

        self::assertSame(0, $users->insertOrIgnore(['name' => 'Another Ada', 'email' => 'ada@x']));
        self::assertSame(1, $users->insertOrIgnore([
            ['name' => 'Another Grace', 'email' => 'grace@x'],
            ['name' => 'Dennis', 'email' => 'dennis@x'],
        ]));
        self::assertSame(0, $users->insertOrIgnore([]));
        self::assertSame(['Ada', 'Grace', 'Linus', 'Margaret', 'Dennis'], $db->table('users')->orderBy('id')->pluck('name'));

        $users->upsert([
            ['name' => 'Ada Lovelace', 'email' => 'ada@x', 'age' => 37],
            ['name' => 'Ken', 'email' => 'ken@x', 'age' => 50],
        ], 'email');
        $ada = $users->find('ada@x', 'email');
        self::assertSame([1, 'Ada Lovelace', 37], [$ada['id'], $ada['name'], $ada['age']], 'The existing row is updated, not replaced.');
        self::assertSame(50, $users->find('ken@x', 'email')['age']);

        $users->upsert(['name' => 'Renamed', 'email' => 'ken@x', 'age' => 51], ['email'], ['age']);
        self::assertSame(['Ken', 51], [$users->find('ken@x', 'email')['name'], $users->find('ken@x', 'email')['age']], 'Only the named columns are overwritten.');

        self::assertSame(0, $users->upsert(['email' => 'ken@x'], 'email'), 'With nothing to overwrite, the existing row is left alone.');
        self::assertSame(0, $users->upsert([], 'email'));
        self::assertSame(6, $users->count());
    }

    #[DataProvider('drivers')]
    public function testALargeResultIsReadInPartsByItsKey(string $driver): void
    {
        $db = $this->seeded($driver);
        $parts = [];
        $db->table('users')->where('id', '>', 1)->chunkById(2, static function (array $rows) use (&$parts, $db): void {
            $parts[] = array_column($rows, 'name');
            // A row removed while reading must not make the next part skip one.
            $db->table('users')->where('id', $rows[0]['id'])->delete();
        });
        self::assertSame([['Grace', 'Linus'], ['Margaret']], $parts);

        $db = $this->seeded($driver);
        $seen = [];
        $db->table('users as u')->select('u.id')->chunkById(1, static function (array $rows) use (&$seen): bool {
            $seen[] = $rows[0]['id'];
            return count($seen) < 2;
        }, 'u.id');
        self::assertSame([1, 2], $seen, 'Returning false stops it.');

        $calls = 0;
        $db->table('users')->where('id', 99)->chunkById(10, static function () use (&$calls): void {
            ++$calls;
        });
        $db->table('users')->chunkById(4, static function () use (&$calls): void {
            ++$calls;
        });
        self::assertSame(1, $calls, 'Nothing is passed when nothing matches, and a full last part needs no empty one after it.');
    }

    #[DataProvider('drivers')]
    public function testAQueryRunsOnTheConnectionItWasStartedFrom(string $driver): void
    {
        $db = $this->seeded($driver);
        try {
            $db->transaction(static function (Connection $tx): void {
                $tx->table('users')->where('id', 1)->lockForUpdate()->first();
                $tx->table('users')->where('id', 1)->update(['name' => 'changed']);
                self::assertSame('changed', $tx->table('users')->where('id', 1)->value('name'));
                throw new \DomainException('undo');
            });
        } catch (\DomainException) {
        }
        self::assertSame('Ada', $db->table('users')->where('id', 1)->value('name'));

        $base = $db->table('users')->where('active', true);
        $older = clone $base;
        $older->where('age', '>', 40);
        self::assertSame([2, 1], [$base->count(), $older->count()], 'A clone branches without changing the query it came from.');
    }

    #[DataProvider('drivers')]
    public function testNamesAreQuotedAndValuesAreBound(string $driver): void
    {
        $db = $this->seeded($driver);
        $hostile = "x'; drop table users; --";
        self::assertSame([], $db->table('users')->where('name', $hostile)->get());
        self::assertSame(5, $db->table('users')->insert(['name' => $hostile, 'email' => $hostile]));
        self::assertSame($hostile, $db->table('users')->where('email', 'like', "x'%")->value('name'));

        foreach (['id" or 1=1 --', 'id` or 1=1 --', 'id; drop table users', 'nmae'] as $column) {
            try {
                $db->table('users')->where($column, 1)->get();
                self::fail("\"{$column}\" is not a column, and the query ran.");
            } catch (DatabaseException $error) {
                self::assertStringNotContainsString($hostile, $error->getMessage());
            }
        }
        self::assertSame(5, $db->table('users')->count(), 'The table is still there, with every row.');
    }

    public function testMistakesAreRefusedBeforeAnySqlRuns(): void
    {
        $db = $this->seeded('sqlite');
        $users = static fn (): QueryContract => $db->table('users');
        $mistakes = [
            'is not a comparison' => static fn () => $users()->where('age', '; drop table users', 1),
            'not a comparison; use one of' => static fn () => $users()->whereColumn('age', 'or', 'id'),
            'int is not a comparison' => static fn () => $users()->having('age', 5, 1),
            'has no value to compare with' => static fn () => $users()->where('age'),
            'cannot be compared with null using ">"' => static fn () => $users()->where('age', '>', null),
            'use whereIn()' => static fn () => $users()->where('id', [1, 2]),
            '"asc" or "desc"' => static fn () => $users()->orderBy('id', 'sideways'),
            'A limit cannot be negative' => static fn () => $users()->limit(-1),
            'An offset cannot be negative' => static fn () => $users()->offset(-1),
            'Pages are numbered from 1' => static fn () => $users()->forPage(0),
            'A chunk holds at least one row' => static fn () => $users()->chunkById(0, static fn () => null),
            'A join needs a condition' => static fn () => $users()->join('posts', 'posts.user_id'),
            'added no condition' => static fn () => $users()->join('posts', static fn () => null),
            'use insertMany()' => static fn () => $users()->insert([['name' => 'a'], ['name' => 'b']]),
            'insert() takes one row' => static fn () => $users()->insert([]),
            'must have the same columns' => static fn () => $users()->insertMany([['name' => 'a', 'email' => 'a'], ['name' => 'b', 'age' => 1]]),
            'same columns' => static fn () => $users()->insertMany([['name' => 'a'], ['name' => 'b', 'email' => 'b']]),
            'A row is given as column => value' => static fn () => $users()->insertMany([['a', 'b']]),
            'columns of a unique key' => static fn () => $users()->upsert(['name' => 'a'], []),
            'update() takes the new values' => static fn () => $users()->update([]),
            'update() uses only the where conditions' => static fn () => $users()->limit(1)->update(['name' => 'a']),
            'delete() uses only the where conditions' => static fn () => $users()->orderBy('id')->delete(),
            'increment() uses only the where conditions' => static fn () => $users()->join('posts', 'posts.user_id', 'users.id')->increment('votes'),
        ];
        foreach ($mistakes as $expected => $mistake) {
            try {
                $mistake();
                self::fail("Not refused: {$expected}");
            } catch (\InvalidArgumentException|\LogicException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
        self::assertSame(4, $db->table('users')->count());
        self::assertSame(['Ada', 'Grace', 'Linus', 'Margaret'], $db->table('users')->orderBy('id')->pluck('name'));

        try {
            $db->table('no_such_table')->where('secret', 'hunter2')->get();
            self::fail('A failing query returned.');
        } catch (DatabaseException $error) {
            self::assertSame('select * from `no_such_table` where `secret` = ?', $error->sql);
            self::assertStringNotContainsString('hunter2', $error->getMessage());
        }
    }

    /**
     * Four users and three posts.
     */
    private function seeded(string $driver): Database
    {
        $db = $this->database($driver);
        $db->table('users')->insertMany([
            ['name' => 'Ada', 'email' => 'ada@x', 'age' => 36, 'active' => true, 'votes' => 1],
            ['name' => 'Grace', 'email' => 'grace@x', 'age' => 45, 'active' => true, 'votes' => 0],
            ['name' => 'Linus', 'email' => 'linus@x', 'age' => null, 'active' => false, 'votes' => 0],
            ['name' => 'Margaret', 'email' => 'margaret@x', 'age' => 90, 'active' => false, 'votes' => 0],
        ]);
        $db->table('posts')->insertMany([
            ['user_id' => 1, 'title' => 'Notes on the engine'],
            ['user_id' => 1, 'title' => 'Numbers'],
            ['user_id' => 2, 'title' => 'An engine compiles'],
        ]);
        return $db;
    }

    private function database(string $driver): Database
    {
        $db = new Database(fn (): \PDO => $this->pdo($driver));
        $db->execute('drop table if exists posts');
        $db->execute('drop table if exists users');
        if ($driver === 'mysql') {
            $db->execute('create table users (id int auto_increment primary key, name varchar(100) not null, email varchar(100) not null unique, age int null, active tinyint(1) not null default 1, votes int not null default 0) engine=InnoDB');
            $db->execute('create table posts (id int auto_increment primary key, user_id int not null, title varchar(100) not null) engine=InnoDB');
        } else {
            $db->execute('create table users (id integer primary key autoincrement, name text not null, email text not null unique, age integer null, active integer not null default 1, votes integer not null default 0)');
            $db->execute('create table posts (id integer primary key autoincrement, user_id integer not null, title text not null)');
        }
        return $db;
    }

    private function pdo(string $driver): \PDO
    {
        if ($driver === 'mysql') {
            return new \PDO(getenv('CRAB_TEST_MYSQL_DSN'), getenv('CRAB_TEST_MYSQL_USER') ?: 'root', getenv('CRAB_TEST_MYSQL_PASSWORD') ?: '');
        }
        return new \PDO('sqlite:' . $this->file);
    }
}
