<?php

declare(strict_types=1);

namespace Crab\Tests\Database;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\InvalidCursor;
use Crab\Contracts\Database\Page;
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
    use \Crab\Tests\Database\Mysql\AsyncDriverMatrix;

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
        return getenv('CRAB_TEST_MYSQL_DSN') === false ? [['sqlite']] : [['sqlite'], ['mysql'], ['mysql-async']];
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
                ->leftJoin('teams as t', static fn(QueryContract $on) => $on->whereColumn('t.id', 'u.team_id')->where(
                    't.open',
                    true,
                ))
                ->where('age', '>=', 18)
                ->where(static fn(QueryContract $group) => $group->where('name', 'like', 'A%')->orWhere('email', null))
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
        self::assertStringStartsWith(
            'select distinct `u`.`id`, `name` as `label`, `u`.*, age * ? as doubled from `users` as `u` inner join `posts`',
            $mysql->toSql(),
        );
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
        self::assertSame(
            [
                'insert into `users` (`email`, `name`) values (?, ?), (?, ?) on conflict (`email`) do update set `name` = excluded.`name`',
                'insert or ignore into `users` (`email`, `name`) values (?, ?), (?, ?)',
                'select * from `users` limit -1 offset ?',
            ],
            $written['sqlite'],
        );
        self::assertSame(
            [
                'insert into `users` (`email`, `name`) values (?, ?), (?, ?) on duplicate key update `name` = values(`name`)',
                'insert ignore into `users` (`email`, `name`) values (?, ?), (?, ?)',
                'select * from `users` limit 18446744073709551615 offset ?',
            ],
            $written['mysql'],
        );

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

        self::assertSame(
            ['Ada', 'Grace', 'Linus'],
            array_column($users->get(), 'name'),
            'Running a query leaves it as it was.',
        );
        self::assertSame(
            ['id' => 2, 'name' => 'Grace'],
            $db->table('users')->select('id', 'name')->where('age', '>', 40)->first(),
        );
        self::assertNull($db->table('users')->where('age', '>', 99)->first());
        self::assertSame('Linus', $users->find(3)['name']);
        self::assertSame('Ada', $users->find('ada@x', 'email')['name']);
        self::assertNull($users->find(99));
        self::assertSame('grace@x', $db->table('users')->where('name', 'Grace')->value('email'));
        self::assertNull($db->table('users')->where('name', 'nobody')->value('email'));
        self::assertSame(['ada@x', 'grace@x', 'linus@x'], $db->table('users')->orderBy('id')->pluck('email'));
        self::assertSame(
            [1 => 'Ada', 2 => 'Grace'],
            $db->table('users')->where('active', true)->pluck('users.name as who', 'users.id'),
        );

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
        $names = static fn(QueryContract $query): array => $query->orderBy('id')->pluck('name');

        self::assertSame(['Ada'], $names($db->table('users')->where('name', 'Ada')));
        self::assertSame(['Grace', 'Linus', 'Margaret'], $names($db->table('users')->where('name', '!=', 'Ada')));
        self::assertSame(['Grace', 'Margaret'], $names($db->table('users')->where('age', '>', 40)));
        self::assertSame(
            ['Ada', 'Grace'],
            $names($db->table('users')->where('name', 'LIKE', '%a%')->where('age', '<=', 45)),
        );
        self::assertSame(['Linus'], $names($db->table('users')->where('age', null)));
        self::assertSame(['Ada', 'Grace', 'Margaret'], $names($db->table('users')->where('age', '!=', null)));
        self::assertSame(['Ada'], $names($db->table('users')->where(['active' => true, 'age' => 36])));
        self::assertSame(['Ada', 'Linus'], $names($db->table('users')->where('name', 'Ada')->orWhere('name', 'Linus')));
        self::assertSame(['Grace'], $names($db
            ->table('users')
            ->where('active', true)
            ->where(static fn(QueryContract $group) => $group->where('age', '>', 80)->orWhere('name', 'Grace'))));
        self::assertSame(['Ada', 'Margaret'], $names($db
            ->table('users')
            ->where('name', 'Ada')
            ->orWhere(static fn(QueryContract $group) => $group->where('active', false)->where('age', '>', 80))));
        self::assertSame(
            ['Ada'],
            $names(
                $db
                    ->table('users')
                    ->where('name', 'Ada')
                    ->where(static fn() => null),
            ),
            'An empty group adds nothing.',
        );

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
        self::assertSame(
            ['Ada', 'Grace', 'Margaret'],
            $names($db->table('users')->where('id', 1)->orWhereNotNull('age')),
        );
        self::assertSame(['Ada', 'Grace'], $names($db->table('users')->whereBetween('age', 36, 45)));
        self::assertSame(['Margaret'], $names($db->table('users')->whereNotBetween('age', 36, 45)));
        self::assertSame(
            ['Ada', 'Margaret'],
            $names($db->table('users')->where('id', 4)->orWhereBetween('age', 30, 40)),
        );
        self::assertSame(
            ['Ada', 'Margaret'],
            $names($db->table('users')->where('id', 1)->orWhereNotBetween('age', 36, 45)),
        );
        self::assertSame(['Ada'], $names($db->table('users')->whereColumn('votes', 'id')));
        self::assertSame(['Grace', 'Linus', 'Margaret'], $names($db->table('users')->whereColumn('votes', '<', 'id')));
        self::assertSame(
            ['Ada', 'Margaret'],
            $names($db->table('users')->where('id', 4)->orWhereColumn('votes', '=', 'id')),
        );
        self::assertSame(['Margaret'], $names($db->table('users')->whereRaw('length(name) > ?', [5])));
        self::assertSame(
            ['Ada', 'Margaret'],
            $names($db->table('users')->where('id', 1)->orWhereRaw('length(name) > ? or id = ?', [5, 99])),
        );

        $search = 'gra';
        $found = $db
            ->table('users')
            ->when($search !== '', static fn(QueryContract $query) => $query->where('name', 'like', "%{$search}%"))
            ->when(
                false,
                static fn(QueryContract $query) => $query->where('id', 0),
                static fn(QueryContract $query) => $query->where('active', true),
            );
        self::assertSame(['Grace'], $names($found));
    }

    #[DataProvider('drivers')]
    public function testJoinsGroupsOrderAndPages(string $driver): void
    {
        $db = $this->seeded($driver);

        $titles = $db
            ->table('posts')
            ->join('users', 'users.id', 'posts.user_id')
            ->select('users.name', 'posts.title')
            ->where('users.active', true)
            ->orderBy('posts.id')
            ->get();
        self::assertSame(
            [
                ['name' => 'Ada', 'title' => 'Notes on the engine'],
                ['name' => 'Ada', 'title' => 'Numbers'],
                ['name' => 'Grace', 'title' => 'An engine compiles'],
            ],
            $titles,
        );

        $counts = $db
            ->table('users as u')
            ->leftJoin('posts as p', static fn(QueryContract $on) => $on->whereColumn('p.user_id', '=', 'u.id')->where(
                'p.title',
                '!=',
                'Numbers',
            ))
            ->select('u.name')
            ->selectRaw('count(p.id) as posts')
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('posts')
            ->orderBy('u.name')
            ->get();
        self::assertSame(
            [['Ada', 1], ['Grace', 1], ['Linus', 0], ['Margaret', 0]],
            array_map(static fn(array $row): array => [$row['name'], (int) $row['posts']], $counts),
        );

        $busy = $db
            ->table('posts')
            ->select('user_id')
            ->selectRaw('count(*) as total')
            ->groupBy('user_id')
            ->having('total', '>', 1)
            ->get();
        self::assertSame(
            [[1, 2]],
            array_map(static fn(array $row): array => [$row['user_id'], (int) $row['total']], $busy),
        );
        $busy = $db
            ->table('posts')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('count(*) = ?', [1])
            ->pluck('user_id');
        self::assertSame([2], $busy);

        self::assertSame(
            [1, 0],
            array_map(
                intval(...),
                $db->table('users')->select('active')->distinct()->orderByDesc('active')->pluck('active'),
            ),
        );
        self::assertSame(
            ['Margaret', 'Grace', 'Ada', 'Linus'],
            $db->table('users')->orderByRaw('age is null, age desc')->pluck('name'),
        );
        self::assertSame(['Margaret', 'Linus'], $db->table('users')->latest('id')->limit(2)->pluck('name'));
        self::assertSame(['Ada', 'Grace'], $db->table('users')->oldest('id')->limit(2)->pluck('name'));
        self::assertSame(['Linus', 'Margaret'], $db->table('users')->orderBy('id')->offset(2)->pluck('name'));
        self::assertSame(['Linus'], $db->table('users')->orderBy('id')->forPage(3, 1)->pluck('name'));
        self::assertSame(
            ['Grace', 'Linus'],
            $db->table('users')->orderBy('id')->forPage(1, 3)->offset(1)->limit(2)->pluck('name'),
        );
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
        // Over rows worked out first, a column named with its table is still found.
        self::assertSame(
            $db->table('users')->orderBy('id')->limit(2)->sum('age'),
            $db->table('users')->orderBy('id')->limit(2)->sum('users.age'),
        );
        // A statement run again takes as many values as before; one left out is not answered with the last caller's.
        self::assertSame(['a' => 10, 'b' => 20], $db->first('select ? as a, ? as b', [10, 20]));
        try {
            $db->first('select ? as a, ? as b', [30]);
            self::fail('A statement was run with a value missing.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('takes 2 values and was given 1', $error->getMessage());
        }
        self::assertSame(0, $db->table('users')->where('id', 99)->sum('age'));
        self::assertSame(57.0, $users->avg('age'));
        self::assertNull($db->table('users')->where('id', 99)->avg('age'));
        self::assertSame([36, 90], [$users->min('age'), $users->max('age')]);
        self::assertNull($db->table('users')->where('id', 99)->max('age'));
        self::assertSame(['Ada', 'Margaret'], [$users->min('name'), $users->max('name')]);
        self::assertTrue($db->table('users')->where('name', 'Ada')->exists());
        self::assertFalse($db->table('users')->where('name', 'nobody')->exists());

        self::assertSame(2, $db->table('users')->select('active')->distinct()->count());
        self::assertSame(
            2,
            $db->table('posts')->select('user_id')->groupBy('user_id')->count(),
            'Groups are counted, not the rows in them.',
        );
        self::assertSame(
            1,
            $db->table('posts')->select('user_id')->groupBy('user_id')->havingRaw('count(*) > 1')->count(),
        );
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
        self::assertSame(
            ['Ada', 'Grace', 'Linus', 'Margaret', 'Dennis'],
            $db->table('users')->orderBy('id')->pluck('name'),
        );

        $users->upsert([
            ['name' => 'Ada Lovelace', 'email' => 'ada@x', 'age' => 37],
            ['name' => 'Ken', 'email' => 'ken@x', 'age' => 50],
        ], 'email');
        $ada = $users->find('ada@x', 'email');
        self::assertSame(
            [1, 'Ada Lovelace', 37],
            [$ada['id'], $ada['name'], $ada['age']],
            'The existing row is updated, not replaced.',
        );
        self::assertSame(50, $users->find('ken@x', 'email')['age']);

        $users->upsert(['name' => 'Renamed', 'email' => 'ken@x', 'age' => 51], ['email'], ['age']);
        self::assertSame(
            ['Ken', 51],
            [$users->find('ken@x', 'email')['name'], $users->find('ken@x', 'email')['age']],
            'Only the named columns are overwritten.',
        );

        self::assertSame(
            0,
            $users->upsert(['email' => 'ken@x'], 'email'),
            'With nothing to overwrite, the existing row is left alone.',
        );
        self::assertSame(0, $users->upsert([], 'email'));
        self::assertSame(6, $users->count());
    }

    #[DataProvider('drivers')]
    public function testALargeResultIsReadInPartsByItsKey(string $driver): void
    {
        $db = $this->seeded($driver);
        $parts = [];
        $db
            ->table('users')
            ->where('id', '>', 1)
            ->chunkById(2, static function (array $rows) use (&$parts, $db): void {
                $parts[] = array_column($rows, 'name');
                // A row removed while reading must not make the next part skip one.
                $db->table('users')->where('id', $rows[0]['id'])->delete();
            });
        self::assertSame([['Grace', 'Linus'], ['Margaret']], $parts);

        $db = $this->seeded($driver);
        $seen = [];
        $db
            ->table('users as u')
            ->select('u.id')
            ->chunkById(
                1,
                static function (array $rows) use (&$seen): bool {
                    $seen[] = $rows[0]['id'];
                    return count($seen) < 2;
                },
                'u.id',
            );
        self::assertSame([1, 2], $seen, 'Returning false stops it.');

        $calls = 0;
        $db
            ->table('users')
            ->where('id', 99)
            ->chunkById(10, static function () use (&$calls): void {
                ++$calls;
            });
        $db->table('users')->chunkById(4, static function () use (&$calls): void {
            ++$calls;
        });
        self::assertSame(
            1,
            $calls,
            'Nothing is passed when nothing matches, and a full last part needs no empty one after it.',
        );

        // An "or" among the query's own conditions does not swallow the place it has read to.
        $db = $this->seeded($driver);
        $read = [];
        $db
            ->table('users')
            ->where('active', true)
            ->orWhere('id', '>', 2)
            ->chunkById(2, static function (array $rows) use (&$read): bool {
                array_push($read, ...array_column($rows, 'id'));
                return count($read) < 20;
            });
        self::assertSame(array_values(array_unique($read)), $read, 'No row is read twice.');
        self::assertSame(
            $db->table('users')->where('active', true)->orWhere('id', '>', 2)->orderBy('id')->pluck('id'),
            $read,
        );
    }

    #[DataProvider('drivers')]
    public function testResultsArePagedWithOrWithoutTheirTotal(string $driver): void
    {
        $db = $this->seeded($driver);
        $users = $db->table('users')->select('name')->orderBy('id');

        $page = $users->paginate(3, 2);
        self::assertInstanceOf(Page::class, $page);
        self::assertSame([['name' => 'Margaret']], $page->items);
        self::assertSame([4, 3, 2, 2, false, 4, 4], [
            $page->total,
            $page->perPage,
            $page->currentPage,
            $page->lastPage,
            $page->hasMore,
            $page->from,
            $page->to,
        ]);
        self::assertSame(
            '{"data":[{"name":"Margaret"}],"current_page":2,"per_page":3,"from":4,"to":4,"has_more":false,"total":4,"last_page":2}',
            json_encode($page),
        );

        $first = $users->paginate(3, (int) 'not a number');
        self::assertSame([1, true, 1, 3, 3], [
            $first->currentPage,
            $first->hasMore,
            $first->from,
            $first->to,
            count($first),
        ]);
        self::assertSame(['Ada', 'Grace', 'Linus'], array_column(iterator_to_array($first), 'name'));
        self::assertSame(
            ['Ada', 'Grace', 'Linus', 'Margaret'],
            $users->pluck('name'),
            'Paging leaves the query as it was.',
        );

        $beyond = $users->paginate(3, 9);
        self::assertSame([[], 4, 2, false, null, null], [
            $beyond->items,
            $beyond->total,
            $beyond->lastPage,
            $beyond->hasMore,
            $beyond->from,
            $beyond->to,
        ]);
        $none = $db->table('users')->where('id', 99)->paginate();
        self::assertSame([[], 0, 15, 1, 1, false], [
            $none->items,
            $none->total,
            $none->perPage,
            $none->currentPage,
            $none->lastPage,
            $none->hasMore,
        ]);
        self::assertSame(
            4,
            $db->table('users')->orderBy('id')->limit(1)->paginate(2)->total,
            'A limit on the query does not cap the total.',
        );
        self::assertSame([2, 2], [
            $db->table('posts')->select('user_id')->groupBy('user_id')->paginate(1)->total,
            $db->table('users')->where('active', true)->paginate(1)->lastPage,
        ]);

        $simple = $users->simplePaginate(3);
        self::assertSame(['Ada', 'Grace', 'Linus'], array_column($simple->items, 'name'));
        self::assertSame([true, null, null, 1, 3], [
            $simple->hasMore,
            $simple->total,
            $simple->lastPage,
            $simple->from,
            $simple->to,
        ]);
        self::assertSame(
            '{"data":[{"name":"Margaret"}],"current_page":2,"per_page":3,"from":4,"to":4,"has_more":false}',
            json_encode($users->simplePaginate(3, 2)),
        );
        self::assertTrue($users->simplePaginate(2, 1)->hasMore);
        self::assertFalse($users->simplePaginate(2, 2)->hasMore, 'A full last page has nothing after it.');
        self::assertSame(
            [1, 2],
            [$users->simplePaginate(2, -5)->currentPage, count($users->simplePaginate(2, -5))],
            'A page below 1 is the first page.',
        );

        $this->expectException(\InvalidArgumentException::class);
        $users->paginate(0);
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
        self::assertSame(
            [2, 1],
            [$base->count(), $older->count()],
            'A clone branches without changing the query it came from.',
        );
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
        $users = static fn(): QueryContract => $db->table('users');
        $mistakes = [
            'is not a comparison' => static fn() => $users()->where('age', '; drop table users', 1),
            'not a comparison; use one of' => static fn() => $users()->whereColumn('age', 'or', 'id'),
            'int is not a comparison' => static fn() => $users()->having('age', 5, 1),
            'has no value to compare with' => static fn() => $users()->where('age'),
            'cannot be compared with null using ">"' => static fn() => $users()->where('age', '>', null),
            'use whereIn()' => static fn() => $users()->where('id', [1, 2]),
            '"asc" or "desc"' => static fn() => $users()->orderBy('id', 'sideways'),
            'A limit cannot be negative' => static fn() => $users()->limit(-1),
            'An offset cannot be negative' => static fn() => $users()->offset(-1),
            'Pages are numbered from 1' => static fn() => $users()->forPage(0),
            'A chunk holds at least one row' => static fn() => $users()->chunkById(0, static fn() => null),
            'A join needs a condition' => static fn() => $users()->join('posts', 'posts.user_id'),
            'added no condition' => static fn() => $users()->join('posts', static fn() => null),
            'use insertMany()' => static fn() => $users()->insert([['name' => 'a'], ['name' => 'b']]),
            'insert() takes one row' => static fn() => $users()->insert([]),
            'must have the same columns' => static fn() => $users()->insertMany([
                ['name' => 'a', 'email' => 'a'],
                ['name' => 'b', 'age' => 1],
            ]),
            'same columns' => static fn() => $users()->insertMany([['name' => 'a'], ['name' => 'b', 'email' => 'b']]),
            'A row is given as column => value' => static fn() => $users()->insertMany([['a', 'b']]),
            'columns of a unique key' => static fn() => $users()->upsert(['name' => 'a'], []),
            'update() takes the new values' => static fn() => $users()->update([]),
            'update() uses only the where conditions' => static fn() => $users()->limit(1)->update(['name' => 'a']),
            'delete() uses only the where conditions' => static fn() => $users()->orderBy('id')->delete(),
            'increment() uses only the where conditions' => static fn() => $users()
                ->join('posts', 'posts.user_id', 'users.id')
                ->increment('votes'),
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

    public function testAQueryInsideAnotherIsWrittenWhereItStandsWithItsValuesInOrder(): void
    {
        $db = $this->database('sqlite');
        $grammar = new SqliteGrammar();
        $posts = new Query($db, 'posts as p', $grammar);
        $posts->selectRaw('count(*)')->whereColumn('p.user_id', 'users.id')->where('p.title', 'like', 'N%');
        $totals = new Query($db, 'posts', $grammar);
        $totals->select('user_id')->selectRaw('count(*) as posts')->groupBy('user_id')->having('posts', '>', 1);
        $average = new Query($db, 'users', $grammar);
        $average->selectRaw('avg(age)')->where('active', true);
        $drafts = new Query($db, 'drafts', $grammar);
        $drafts->whereColumn('drafts.user_id', 'users.id')->where('drafts.kind', 'note');
        $query = new Query($db, 'users', $grammar);
        $query
            ->select('name')
            ->selectSub($posts, 'notes')
            ->joinSub($totals, 'totals', 'totals.user_id', '=', 'users.id')
            ->where('votes', '>=', 0)
            ->where('age', '>', $average)
            ->whereNotExists($drafts)
            ->whereIn('users.id', new Query($db, 'roles', $grammar)
                ->select('user_id')
                ->where('name', 'admin'))
            ->groupByRaw('length(name) > ?', [3])
            ->orderBy('name');

        self::assertSame(
            'select `name`, (select count(*) from `posts` as `p` where `p`.`user_id` = `users`.`id` and `p`.`title` like ?) as `notes`'
            . ' from `users` inner join (select `user_id`, count(*) as posts from `posts` group by `user_id` having `posts` > ?) as `totals`'
            . ' on `totals`.`user_id` = `users`.`id`'
            . ' where `votes` >= ? and `age` > (select avg(age) from `users` where `active` = ?)'
            . ' and not exists (select * from `drafts` where `drafts`.`user_id` = `users`.`id` and `drafts`.`kind` = ?)'
            . ' and `users`.`id` in (select `user_id` from `roles` where `name` = ?)'
            . ' group by length(name) > ? order by `name` asc',
            $query->toSql(),
        );
        self::assertSame(['N%', 1, 0, true, 'note', 'admin', 3], $query->bindings());
    }

    #[DataProvider('drivers')]
    public function testAnotherQueryStandsForAValueAColumnOrATable(string $driver): void
    {
        $db = $this->seeded($driver);
        $posts = static fn(): QueryContract => $db->table('posts as p')->whereColumn('p.user_id', 'users.id');

        // Rebit's recently played: the latest of each, among those that have any.
        $recent = $db
            ->table('users')
            ->select('users.id', 'name')
            ->selectSub($posts()->selectRaw('max(p.id)'), 'last_post')
            ->whereExists($posts())
            ->orderByDesc('last_post')
            ->orderByDesc('users.id');
        self::assertSame(
            [['name' => 'Grace', 'last_post' => 3], ['name' => 'Ada', 'last_post' => 2]],
            array_map(static fn(array $row): array => [
                'name' => $row['name'],
                'last_post' => (int) $row['last_post'],
            ], $recent->get()),
        );
        self::assertSame(
            ['Linus', 'Margaret'],
            $db->table('users')->whereNotExists($posts())->orderBy('id')->pluck('name'),
        );
        self::assertSame(
            ['Grace', 'Linus'],
            $db
                ->table('users')
                ->whereExists($posts()->where('p.title', 'like', 'An%'))
                ->orWhereNotExists($posts())
                ->where('age', null)
                ->orWhere('id', 2)
                ->orderBy('id')
                ->pluck('name'),
        );
        self::assertSame(
            ['Margaret'],
            $db->table('users')->where('age', '>', $db->table('users')->selectRaw('avg(age)'))->pluck('name'),
        );

        $totals = $db->table('posts')->select('user_id')->selectRaw('count(*) as posts')->groupBy('user_id');
        self::assertSame(
            [1],
            array_map(intval(...), $db->table($totals, 'totals')->where('posts', '>', 1)->pluck('user_id')),
        );
        self::assertSame(2, $db->table($totals, 'totals')->count());
        self::assertSame(
            [['Ada', 2], ['Grace', 1]],
            array_map(static fn(array $row): array => [
                $row['name'],
                (int) $row['posts'],
            ], $db->table('users')->joinSub($totals, 'totals', 'totals.user_id', '=', 'users.id')->select('users.name', 'totals.posts')->orderBy('users.id')->get()),
        );
        self::assertSame(
            ['Ada', 'Grace', 'Linus', 'Margaret'],
            $db
                ->table('users')
                ->leftJoinSub($totals, 'totals', 'totals.user_id', '=', 'users.id')
                ->orderBy('users.id')
                ->pluck('name'),
        );
        self::assertSame(
            [1, 2],
            array_map(
                intval(...),
                array_column(
                    $db
                        ->table('posts')
                        ->selectRaw('count(*) as posts')
                        ->groupByRaw('length(title) > ?', [10])
                        ->orderBy('posts')
                        ->get(),
                    'posts',
                ),
            ),
        );

        self::assertSame(2, $db->table('users')->whereExists($posts())->update(['votes' => 5]));
        self::assertSame(['Ada', 'Grace'], $db->table('users')->where('votes', 5)->orderBy('id')->pluck('name'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('reads the rows of another query');
        $db->table($totals, 'totals')->delete();
    }

    #[DataProvider('drivers')]
    public function testAJsonColumnIsReadAtAPath(string $driver): void
    {
        $db = $this->database($driver);
        $db->execute('drop table if exists events');
        $db->execute(
            $driver === 'sqlite'
                ? 'create table events (id integer primary key, data text not null)'
                : 'create table events (id int primary key, data json not null) engine=InnoDB',
        );
        $db->table('events')->insertMany([
            ['id' => 1, 'data' => '{"actor":{"id":2,"name":"Grace"},"campaign":"pro","seen":true,"tags":["a","b"]}'],
            ['id' => 2, 'data' => '{"actor":{"id":3},"campaign":null,"seen":false,"tags":[]}'],
            ['id' => 3, 'data' => '{"actor":null,"seen":false}'],
            ['id' => 4, 'data' => '{"commenter":{"id":2}}'],
        ]);
        $ids = static fn(QueryContract $query): array => array_map(intval(...), $query->orderBy('id')->pluck('id'));
        $events = static fn(): QueryContract => $db->table('events');

        self::assertSame([1], $ids($events()->where('data->actor->id', 2)));
        self::assertSame([1, 4], $ids($events()->where('data->actor->id', 2)->orWhere('data->commenter->id', 2)));
        self::assertSame([1], $ids($events()->where('data->actor->name', 'like', 'G%')));
        // Missing, or JSON's null, alike.
        self::assertSame([3, 4], $ids($events()->whereNull('data->actor->id')));
        self::assertSame([2, 3, 4], $ids($events()->whereNull('data->campaign')));
        self::assertSame([1], $ids($events()->whereNotNull('data->campaign')));
        self::assertSame([1], $ids($events()->where('data->seen', true)));
        self::assertSame([2, 3], $ids($events()->where('data->seen', false)));
        self::assertSame(
            [2, 3, 4],
            $ids($events()->where(static fn(QueryContract $q) => $q->whereNull(
                'data->actor->id',
            )->orWhereNotIn('data->actor->id', [2]))),
        );
        self::assertSame(
            ['tag' => 'b', 'campaign' => 'pro'],
            $events()->select('data->tags[1] as tag', 'data->campaign as campaign')->where('id', 1)->first(),
        );

        foreach ([
            'which a query reads but does not write' => static fn() => $events()
                ->where('id', 1)
                ->update(['data->seen' => true]),
            'is not a key of a JSON path' => static fn() => $events()->where("data->x') or 1=1 --", 1)->get(),
        ] as $expected => $mistake) {
            try {
                $mistake();
                self::fail("Not refused: {$expected}");
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
        $db->execute('drop table events');
    }

    #[DataProvider('drivers')]
    public function testACursorPagesThroughTheOrderWithoutSkippingOrRepeating(string $driver): void
    {
        $db = $this->database($driver);
        $db->execute('drop table if exists games');
        $db->execute(
            $driver === 'sqlite'
                ? 'create table games (id integer primary key, title text null, played int null)'
                : 'create table games (id int primary key, title varchar(100) null, played int null) engine=InnoDB',
        );
        $titles = ['Zelda', 'zelda', ' ', null, 'Mario', 'mario', '', 'Metroid', 'Kirby', 'Zelda'];
        $played = [5, null, 5, 2, null, 9, 5, null, 1, 9];
        $rows = [];
        foreach ($titles as $index => $title) {
            $rows[] = ['id' => $index + 1, 'title' => $title, 'played' => $played[$index]];
        }
        $db->table('games')->insertMany($rows);

        $walk = static function (QueryContract $query, int $perPage = 3, string $unique = 'id'): array {
            $ids = [];
            $cursor = null;
            do {
                $page = $query->cursorPaginate($perPage, $cursor, $unique);
                self::assertLessThanOrEqual($perPage, count($page));
                array_push($ids, ...array_map(static fn(array $row): int => (int) $row['id'], $page->items));
                $cursor = $page->nextCursor;
            } while ($cursor !== null);
            return $ids;
        };
        $all = static fn(QueryContract $query): array => array_map(intval(...), array_column($query->get(), 'id'));

        // Rebit's alphabetical library: blank and missing titles alike, ties broken by id.
        $alphabetical = $db
            ->table('games')
            ->select('id', 'title')
            ->selectRaw("lower(coalesce(nullif(trim(title), ''), '')) as sort_title")
            ->orderBy('sort_title')
            ->orderBy('id');
        self::assertSame([3, 4, 7, 9, 5, 6, 8, 1, 2, 10], $all($alphabetical));
        self::assertSame($all($alphabetical), $walk($alphabetical));
        self::assertSame($all($alphabetical), $walk($alphabetical, 1));

        foreach ([
            $db->table('games')->orderByDesc('played')->orderByDesc('id'),
            $db->table('games')->orderBy('played')->orderBy('id'),
            $db->table('games')->orderBy('played')->orderByDesc('id'),
            $db->table('games')->orderByDesc('played')->orderBy('id'),
            $db->table('games')->where('played', '>', 1)->orWhere('title', null)->orderByDesc('played')->orderBy('id'),
        ] as $order) {
            self::assertSame($all($order), $walk($order, 2));
            self::assertSame($all($order), $walk($order, 4));
        }
        self::assertSame(range(1, 10), $walk($db->table('games')), 'Unordered, it is by the unique column.');
        $grouped = $db
            ->table('games')
            ->select('played')
            ->selectRaw('count(*) as games')
            ->whereNotNull('played')
            ->groupBy('played')
            ->orderByDesc('games')
            ->orderBy('played');
        self::assertSame([5, 9, 1, 2], array_map(static fn(array $row): int => (int) $row['played'], [
            ...$grouped->cursorPaginate(2, null, 'played'),
            ...$grouped->cursorPaginate(2, $grouped->cursorPaginate(2, null, 'played')->nextCursor, 'played'),
        ]));

        // A row added while the client scrolls does not shift what follows.
        $newest = $db->table('games')->orderByDesc('id');
        $first = $newest->cursorPaginate(3);
        self::assertSame([10, 9, 8], array_map(intval(...), array_column($first->items, 'id')));
        $db->table('games')->insert(['id' => 11, 'title' => 'Tetris', 'played' => null]);
        self::assertSame(
            [7, 6, 5],
            array_map(intval(...), array_column($newest->cursorPaginate(3, $first->nextCursor)->items, 'id')),
        );

        $last = $db->table('games')->where('id', '>', 9)->orderBy('id')->cursorPaginate(5);
        self::assertSame([false, null], [$last->hasMore, $last->nextCursor]);
        self::assertSame(
            ['data' => [['id' => 10], ['id' => 11]], 'per_page' => 5, 'next_cursor' => null, 'has_more' => false],
            json_decode(
                (string) json_encode($db->table('games')->select('id')->where('id', '>', 9)->cursorPaginate(5)),
                true,
            ),
        );

        // A cursor is only good for the order that gave it.
        $alphabeticalCursor = $alphabetical->cursorPaginate(3)->nextCursor;
        foreach (['not a cursor', '', base64_encode('[1,2]'), (string) $alphabeticalCursor] as $cursor) {
            try {
                $newest->cursorPaginate(3, $cursor);
                self::fail("The cursor \"{$cursor}\" was read.");
            } catch (InvalidCursor $invalid) {
                self::assertSame(422, $invalid->status);
            }
        }

        foreach ([
            'must end with a column no two rows share' => static fn() => $db
                ->table('games')
                ->orderBy('title')
                ->cursorPaginate(),
            'order by the alias' => static fn() => $db
                ->table('games')
                ->orderByRaw('lower(title)')
                ->orderBy('id')
                ->cursorPaginate(),
            'does not select it' => static fn() => $db
                ->table('games')
                ->select('title')
                ->orderBy('id')
                ->cursorPaginate(2),
            'A page holds at least one row' => static fn() => $db->table('games')->cursorPaginate(0),
        ] as $expected => $mistake) {
            try {
                $mistake();
                self::fail("Not refused: {$expected}");
            } catch (\InvalidArgumentException|\LogicException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
        $db->execute('drop table games');
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
        $db = new Database(fn(): \PDO => $this->pdo($driver), factory: $this->mysqlFactory($driver));
        $db->execute('drop table if exists posts');
        $db->execute('drop table if exists users');
        if ($driver === 'mysql') {
            $db->execute(
                'create table users (id int auto_increment primary key, name varchar(100) not null, email varchar(100) not null unique, age int null, active tinyint(1) not null default 1, votes int not null default 0) engine=InnoDB',
            );
            $db->execute(
                'create table posts (id int auto_increment primary key, user_id int not null, title varchar(100) not null) engine=InnoDB',
            );
        } else {
            $db->execute(
                'create table users (id integer primary key autoincrement, name text not null, email text not null unique, age integer null, active integer not null default 1, votes integer not null default 0)',
            );
            $db->execute(
                'create table posts (id integer primary key autoincrement, user_id integer not null, title text not null)',
            );
        }
        return $db;
    }

    private function pdo(string $driver): \PDO
    {
        if ($driver === 'mysql') {
            return new \PDO(
                getenv('CRAB_TEST_MYSQL_DSN'),
                getenv('CRAB_TEST_MYSQL_USER') ?: 'root',
                getenv('CRAB_TEST_MYSQL_PASSWORD') ?: '',
            );
        }
        return new \PDO('sqlite:' . $this->file);
    }
}
