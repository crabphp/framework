<?php

declare(strict_types=1);

namespace Crab\Tests\Queue;

use Crab\Contracts\Queue\Store;
use Crab\Database\Database;
use Crab\Queue\DatabaseStore;
use Crab\Queue\MemoryStore;
use Crab\Queue\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StoreParityJob
{
    public function handle(mixed $value): void {}
}

final class StoreParityTest extends TestCase
{
    private ?Database $database = null;

    protected function tearDown(): void
    {
        $this->database?->close();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function stores(): array
    {
        return ['memory' => ['memory'], 'database' => ['database']];
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidPayloads(): array
    {
        $cases = [];
        foreach (['memory', 'database'] as $store) {
            foreach ([
                'infinity' => INF,
                'not a number' => NAN,
                'invalid UTF-8' => ['text' => "\xff"],
            ] as $name => $value) {
                $cases[$store . ': ' . $name] = [$store, $value];
            }
        }
        return $cases;
    }

    #[DataProvider('invalidPayloads')]
    public function testAStoreRefusesDataThatCannotBeStoredAsJson(string $store, mixed $value): void
    {
        $queue = new Queue($this->store($store));

        $this->expectException(\JsonException::class);
        $queue->push(StoreParityJob::class, value: $value);
    }

    #[DataProvider('stores')]
    public function testAStoreKeepsThePayloadAsItWasWhenPushed(string $kind): void
    {
        $store = $this->store($kind);
        $text = 'before';
        $data = ['value' => ['text' => &$text, 'ratio' => 1.0, 'list' => [null, false, 7]]];
        $store->push('default', StoreParityJob::class, $data, 10, 10);
        $text = 'after';

        $message = $store->reserve(['default'], 10, 90);

        self::assertNotNull($message);
        self::assertSame(['value' => ['text' => 'before', 'ratio' => 1.0, 'list' => [null, false, 7]]], $message->data);
    }

    #[DataProvider('stores')]
    public function testAnUnknownJobStateNamesTheAcceptedStates(string $kind): void
    {
        $store = $this->store($kind);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('pending, delayed or processing');
        $store->jobs('default', 'typo', 10);
    }

    private function store(string $kind): Store
    {
        if ($kind === 'memory') {
            return new MemoryStore();
        }
        $this->database = new Database(static fn(): \PDO => new \PDO('sqlite::memory:'), max: 1);
        $this->database->execute(
            'create table jobs (id integer primary key, queue text, job text, payload text, attempts integer, '
            . 'available_at integer, created_at integer, reserved_at integer, failed_at integer, error text)',
        );
        return new DatabaseStore($this->database);
    }
}
