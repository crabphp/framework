<?php

declare(strict_types=1);

namespace Crab\Tests\Database;

use Crab\Contracts\Database\Cast;
use Crab\Contracts\Database\CursorPage;
use Crab\Contracts\Database\Page;
use Crab\Database\ColumnName;
use Crab\Database\Database;
use Crab\Database\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rows read into records with Query::into(), and records written back.
 * Runs against SQLite always, and against MySQL too when
 * CRAB_TEST_MYSQL_DSN names a database the tests may fill.
 */
final class RecordTest extends TestCase
{
    use \Crab\Tests\Database\Mysql\AsyncDriverMatrix;

    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/crab-record-' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        if (getenv('CRAB_TEST_MYSQL_DSN') !== false) {
            $this->pdo('mysql')->exec('drop table if exists record_orders');
        }
    }

    public static function drivers(): array
    {
        return getenv('CRAB_TEST_MYSQL_DSN') === false ? [['sqlite']] : [['sqlite'], ['mysql'], ['mysql-async']];
    }

    #[DataProvider('drivers')]
    public function testRowsAreReadIntoRecordsOfTheTypesTheyDeclare(string $driver): void
    {
        $db = $this->database($driver);
        $id = $db->table('record_orders')->insert(
            new NewRecordOrder(
                RecordStatus::Paid,
                1250,
                true,
                ['tags' => ['gift'], 'note' => 'Ünïcode'],
                new \DateTimeImmutable('2026-10-28 09:30:00'),
                12.5,
            ),
        );

        $order = $db->table('record_orders')->into(RecordOrder::class)->find($id);

        self::assertEquals(
            new RecordOrder(
                (int) $id,
                RecordStatus::Paid,
                1250,
                true,
                ['tags' => ['gift'], 'note' => 'Ünïcode'],
                new \DateTimeImmutable('2026-10-28 09:30:00'),
                null,
                12.5,
            ),
            $order,
        );
        self::assertSame(1250, $order->total, 'An int column is an int, whatever the driver gave.');
        self::assertSame(12.5, $order->price);
        self::assertTrue($order->paid);
        self::assertNull($db->table('record_orders')->into(RecordOrder::class)->find(999));
    }

    #[DataProvider('drivers')]
    public function testAQueryReadsOnlyTheColumnsItsRecordDeclares(string $driver): void
    {
        $db = $this->database($driver);
        $this->seed($db);

        $query = $db->table('record_orders')->into(RecordTotal::class)->where('total', '>', 100);
        self::assertStringContainsString('record_orders', $query->toSql());
        self::assertStringNotContainsString(
            'secret',
            $query->toSql(),
            'A column the record does not declare is not read.',
        );
        self::assertEquals([new RecordTotal(1, 1250), new RecordTotal(2, 400)], $query->orderBy('id')->get());

        // With an alias and a join, the record's columns are the aliased table's.
        $joined = $db
            ->table('record_orders as o')
            ->join('record_orders as twin', 'twin.id', '=', 'o.id')
            ->into(RecordTotal::class)
            ->orderBy('o.id')
            ->first();
        self::assertEquals(new RecordTotal(1, 1250), $joined);

        // Columns chosen with select() are read as chosen.
        $chosen = $db->table('record_orders')->select('id', 'total')->into(RecordTotal::class)->find(3);
        self::assertEquals(new RecordTotal(3, 80), $chosen);
    }

    #[DataProvider('drivers')]
    public function testPagesAndChunksHoldRecords(string $driver): void
    {
        $db = $this->database($driver);
        $this->seed($db);
        $query = $db->table('record_orders')->into(RecordTotal::class)->orderBy('id');

        $page = $query->paginate(2, 1);
        self::assertInstanceOf(Page::class, $page);
        self::assertEquals([new RecordTotal(1, 1250), new RecordTotal(2, 400)], $page->items);
        self::assertSame(3, $page->total);
        self::assertEquals([new RecordTotal(3, 80)], $query->simplePaginate(2, 2)->items);

        $first = $query->cursorPaginate(2);
        self::assertInstanceOf(CursorPage::class, $first);
        self::assertEquals([new RecordTotal(1, 1250), new RecordTotal(2, 400)], $first->items);
        $second = $query->cursorPaginate(2, $first->nextCursor);
        self::assertEquals([new RecordTotal(3, 80)], $second->items);
        self::assertNull($second->nextCursor);

        $chunks = [];
        $query->chunkById(2, static function (array $records) use (&$chunks): void {
            $chunks[] = $records;
        });
        self::assertEquals([[new RecordTotal(1, 1250), new RecordTotal(2, 400)], [new RecordTotal(3, 80)]], $chunks);

        // Plain values stay plain.
        self::assertSame(1250, (int) $query->value('total'));
        self::assertSame(3, $query->count());
        self::assertSame([1250, 400, 80], array_map(intval(...), $query->pluck('total')));
    }

    #[DataProvider('drivers')]
    public function testWritesTurnEnumsDatesAndJsonIntoWhatTheColumnsHold(string $driver): void
    {
        $db = $this->database($driver);
        $this->seed($db);

        $orders = $db->table('record_orders')->into(RecordOrder::class);
        $updated = (clone $orders)
            ->where('id', 2)
            ->update([
                'status' => RecordStatus::Shipped,
                'placed_at' => new \DateTimeImmutable('2026-11-01 08:00:00'),
                'meta' => ['tags' => []],
            ]);
        self::assertSame(1, $updated);
        $row = $db->table('record_orders')->select('status', 'placed_at')->find(2);
        self::assertSame(['status' => 'shipped', 'placed_at' => '2026-11-01 08:00:00'], $row);
        $order = (clone $orders)->find(2);
        self::assertSame(RecordStatus::Shipped, $order->status);
        self::assertSame(['tags' => []], $order->meta);

        // A list of records, and arrays through the map of into()'s class.
        self::assertSame(2, $db->table('record_orders')->insertMany([
            new NewRecordOrder(RecordStatus::Paid, 1, false, [], new \DateTimeImmutable('2026-01-01'), 0.5),
            new NewRecordOrder(RecordStatus::Paid, 2, false, [], new \DateTimeImmutable('2026-01-02'), 0.5),
        ]));
        (clone $orders)->insert([
            'status' => RecordStatus::Pending,
            'total' => 3,
            'paid' => false,
            'meta' => ['a' => 1],
            'placed_at' => new \DateTimeImmutable('2026-01-03'),
            'price' => 1.0,
        ]);
        self::assertSame(
            ['1', '2', '3'],
            array_map(
                static fn(RecordOrder $order): string => (string) $order->total,
                (clone $orders)->where('total', '<', 10)->orderBy('total')->get(),
            ),
        );
    }

    #[DataProvider('drivers')]
    public function testAColumnCanHaveAnotherNameAndACastOfTheApplicationsOwn(string $driver): void
    {
        $db = $this->database($driver);
        $this->seed($db);

        $record = $db->table('record_orders')->into(RecordRenamed::class)->find(1);
        self::assertEquals(new RecordRenamed(1, new RecordCents(1250)), $record);

        $db
            ->table('record_orders')
            ->where('id', 1)
            ->into(RecordRenamed::class)
            ->update(['total' => new RecordCents(99)]);
        self::assertSame(99, (int) $db->table('record_orders')->where('id', 1)->value('total'));
    }

    public function testAValueIsNeverQuietlyMadeAnother(): void
    {
        $db = $this->database('sqlite');
        $this->seed($db);
        $db->execute("update record_orders set status = 'lost' where id = 1");
        $db->execute("update record_orders set total = 'many' where id = 2");

        $read = static function (\Closure $read, string $expected): void {
            try {
                $read();
                self::fail('The value was read.');
            } catch (\UnexpectedValueException $error) {
                $message = $error->getMessage();
            }
            self::assertSame($expected, $message);
        };
        $read(
            static fn() => $db->table('record_orders')->into(RecordOrder::class)->find(1),
            RecordOrder::class
            . '::$status takes '
            . RecordStatus::class
            . ', and the column "status" held text that is not one.',
        );
        $read(static fn() => $db
            ->table('record_orders')
            ->into(RecordTotal::class)
            ->find(2), RecordTotal::class . '::$total takes int, and the column "total" held text that is not one.');
        $read(static fn() => $db
            ->table('record_orders')
            ->into(RecordNote::class)
            ->find(
                3,
            ), RecordNote::class . '::$note cannot be null, and the column "note" is null. Declare it nullable or select a value.');

        try {
            $db->table('record_orders')->select('id')->into(RecordTotal::class)->find(3);
            self::fail('A record was made without its column.');
        } catch (\LogicException $error) {
            $missing = $error->getMessage();
        }
        self::assertSame(
            RecordTotal::class
            . '::$total reads the column "total", which the query did not select. Select it, or give the parameter a default.',
            $missing,
        );
        self::assertEquals(
            new RecordWithDefault(3),
            $db->table('record_orders')->select('id')->into(RecordWithDefault::class)->find(3),
            'A parameter with a default may go unselected.',
        );
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('unfitClasses')]
    public function testAClassThatCannotBeARecordIsRefusedWhenNamed(string $class, string $message): void
    {
        $db = $this->database('sqlite');
        try {
            $db->table('record_orders')->into($class);
            self::fail('The class was taken as a record.');
        } catch (\LogicException $error) {
            $refused = $error->getMessage();
        }
        self::assertSame($message, $refused);
    }

    public static function unfitClasses(): array
    {
        return [
            'untyped' => [
                RecordUntyped::class,
                RecordUntyped::class
                    . '::$id has no type; declare one, such as int $id, so that its column can be read.',
            ],
            'array' => [
                RecordArray::class,
                RecordArray::class
                    . '::$tags is an array, which a column does not hold by itself; mark a JSON column #[Json].',
            ],
            'not promoted' => [
                RecordUnpromoted::class,
                RecordUnpromoted::class
                    . '::$id must be a public property declared in the constructor, public function __construct(public int $id), so that it can be read and written.',
            ],
            'mutable date' => [
                RecordMutableDate::class,
                RecordMutableDate::class
                    . '::$at is a DateTime; declare it DateTimeImmutable, as a record does not change.',
            ],
            'one column twice' => [
                RecordTwice::class,
                RecordTwice::class . ' cannot be a record: $total and $amount both map to the column "total".',
            ],
            'no class' => ['Nowhere\\Order', 'Nowhere\\Order cannot be a record: there is no such class.'],
        ];
    }

    private function seed(Database $db): void
    {
        foreach ([[1250, 'paid'], [400, 'pending'], [80, 'paid']] as [$total, $status]) {
            $db->table('record_orders')->insert([
                'status' => $status,
                'total' => $total,
                'paid' => $status === 'paid',
                'meta' => '{}',
                'placed_at' => '2026-10-28 09:30:00',
                'price' => 1.5,
                'secret' => 'hash',
            ]);
        }
    }

    private function database(string $driver): Database
    {
        $db = new Database(fn(): \PDO => $this->pdo($driver), factory: $this->mysqlFactory($driver));
        $db->execute('drop table if exists record_orders');
        if ($driver === 'mysql') {
            $db->execute(
                'create table record_orders (id int auto_increment primary key, status varchar(20) not null, total int not null, paid tinyint(1) not null, meta json not null, placed_at datetime not null, note varchar(100) null, price decimal(8,2) not null, secret varchar(100) null) engine=InnoDB',
            );
        } else {
            $db->execute(
                'create table record_orders (id integer primary key autoincrement, status text not null, total integer not null, paid integer not null, meta text not null, placed_at text not null, note text null, price real not null, secret text null)',
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

enum RecordStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Shipped = 'shipped';
}

final readonly class RecordOrder
{
    public function __construct(
        public int $id,
        public RecordStatus $status,
        public int $total,
        public bool $paid,
        #[Json]
        public array $meta,
        public \DateTimeImmutable $placed_at,
        public ?string $note,
        public float $price,
    ) {}
}

/**
 * A new row: everything but the id the database gives it.
 */
final readonly class NewRecordOrder
{
    public function __construct(
        public RecordStatus $status,
        public int $total,
        public bool $paid,
        #[Json]
        public array $meta,
        public \DateTimeImmutable $placed_at,
        public float $price,
    ) {}
}

final readonly class RecordTotal
{
    public function __construct(
        public int $id,
        public int $total,
    ) {}
}

final readonly class RecordNote
{
    public function __construct(
        public int $id,
        public string $note,
    ) {}
}

final readonly class RecordWithDefault
{
    public function __construct(
        public int $id,
        public int $total = 0,
    ) {}
}

final readonly class RecordCents
{
    public function __construct(
        public int $cents,
    ) {}
}

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class AsCents implements Cast
{
    public function read(mixed $value): mixed
    {
        return new RecordCents((int) $value);
    }

    public function write(mixed $value): mixed
    {
        return $value->cents;
    }
}

final readonly class RecordRenamed
{
    public function __construct(
        public int $id,
        #[ColumnName('total'), AsCents]
        public RecordCents $amount,
    ) {}
}

final class RecordUntyped
{
    public function __construct(
        public $id,
    ) {}
}

final readonly class RecordArray
{
    public function __construct(
        public array $tags,
    ) {}
}

final class RecordUnpromoted
{
    public int $id;

    public function __construct(int $id)
    {
        $this->id = $id;
    }
}

final readonly class RecordMutableDate
{
    public function __construct(
        public \DateTime $at,
    ) {}
}

final readonly class RecordTwice
{
    public function __construct(
        public int $total,
        #[ColumnName('total')]
        public int $amount,
    ) {}
}
