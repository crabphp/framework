<?php

declare(strict_types=1);

namespace Crab\Tests\Console;

use Crab\Console\Console;
use Crab\Console\Output;
use Crab\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseOptionsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function invalidNames(): iterable
    {
        foreach (['migrate', 'migrate:rollback', 'migrate:status', 'make:migration'] as $name) {
            foreach ([
                ['--database'],
                ['--database', 'main'],
                ['--database='],
                ['--database=unknown'],
                ["--database=main\n"],
            ] as $i => $arguments) {
                yield $name . ' ' . $i => [$name, $arguments];
            }
        }
    }

    #[DataProvider('invalidNames')]
    public function testADatabaseOptionIsRejectedBeforeResolvingAConnection(string $name, array $arguments): void
    {
        // Resolving main would fail on its DSN; malformed input must be diagnosed first.
        $manager = new DatabaseManager(['main' => ['dsn' => '']], 'main');
        $command = match ($name) {
            'migrate' => new \Crab\Database\Commands\Migrate($manager),
            'migrate:rollback' => new \Crab\Database\Commands\Rollback($manager),
            'migrate:status' => new \Crab\Database\Commands\Status($manager),
            default => new \Crab\Database\Commands\MakeMigration($manager),
        };
        $console = new Console();
        $console->add($name, $command);
        $stream = fopen('php://memory', 'w+b');
        try {
            $status = $console->run(
                [$name, ...($name === 'make:migration' ? ['create_orders_table'] : []), ...$arguments],
                new Output($stream, $stream),
            );
            rewind($stream);
            $output = stream_get_contents($stream);
            self::assertSame(1, $status, $output);
            self::assertStringContainsString('--database=', $output);
            self::assertStringNotContainsString('dsn', $output);
        } finally {
            fclose($stream);
        }
    }

    public function testAnUnusableMigrationNameIsRefusedBeforeResolvingItsDatabase(): void
    {
        $manager = new DatabaseManager(['main' => ['dsn' => '']], 'main');
        $console = new Console();
        $console->add('make:migration', new \Crab\Database\Commands\MakeMigration($manager));
        $stream = fopen('php://memory', 'w+b');
        try {
            $status = $console->run(['make:migration', '???'], new Output($stream, $stream));
            rewind($stream);
            $output = stream_get_contents($stream);
            self::assertSame(1, $status, $output);
            self::assertStringContainsString('make:migration create_users_table', $output);
            self::assertStringNotContainsString('dsn', $output);
        } finally {
            fclose($stream);
        }
    }
}
