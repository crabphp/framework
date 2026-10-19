<?php

declare(strict_types=1);

namespace Crab\Tests\Console;

use Crab\Config\Config;
use Crab\Console\Console;
use Crab\Console\Output;
use Crab\Contracts\Database\Migrator;
use Crab\Contracts\Database\Migrators;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeneratorWriteTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function installers(): iterable
    {
        foreach (['queue:install', 'cache:install', 'token:install'] as $command) {
            yield $command => [$command];
        }
    }

    #[DataProvider('installers')]
    public function testAnInstallerRefusesAFailedFinalWriteWithoutClaimingSuccess(string $name): void
    {
        $directory = sys_get_temp_dir() . '/crab-installer-write-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $migrator = $this->createStub(Migrator::class);
        $migrator->method('status')->willReturn([]);
        $migrator->method('make')->willReturn($directory);
        $migrators = $this->createStub(Migrators::class);
        $migrators->method('migrator')->willReturn($migrator);
        $config = new Config(['queue' => ['table' => 'jobs'], 'cache' => ['table' => 'cache']]);
        $command = match ($name) {
            'queue:install' => new \Crab\Queue\Commands\InstallQueue($migrators, $config),
            'cache:install' => new \Crab\Cache\Commands\InstallCache($migrators, $config),
            default => new \Crab\Auth\Commands\InstallTokens($migrator),
        };
        $console = new Console();
        $console->add($name, $command);
        $stream = fopen('php://memory', 'w+b');
        try {
            $status = $console->run([$name], new Output($stream, $stream));
            rewind($stream);
            $output = stream_get_contents($stream);
            self::assertSame(1, $status, $output);
            self::assertStringContainsString($directory, $output);
            self::assertStringContainsString('permissions', $output);
            self::assertStringNotContainsString('Created ', $output);
            self::assertStringNotContainsString('Run `migrate`', $output);
            self::assertDirectoryExists($directory);
        } finally {
            fclose($stream);
            rmdir($directory);
        }
    }

    #[DataProvider('installers')]
    public function testAWritableInstallerCreatesItsCompleteTableAndDoesNotOverwriteTheMigration(string $name): void
    {
        $directory = sys_get_temp_dir() . '/crab-installer-table-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $app = new \Crab\App(new Config([
            'database' => [
                'connections' => ['main' => [
                    'dsn' => 'sqlite::memory:',
                    'migrations' => $directory,
                ]],
            ],
        ]));
        foreach ([
            \Crab\Database\DatabaseProvider::class,
            \Crab\Queue\QueueProvider::class,
            \Crab\Cache\CacheProvider::class,
            \Crab\Auth\TokenAuthProvider::class,
        ] as $provider) {
            $app->register($provider);
        }
        $stream = fopen('php://memory', 'w+b');
        $output = new Output($stream, $stream);
        try {
            self::assertSame(0, $app->console->run([$name], $output));
            $files = glob($directory . '/*.php');
            self::assertCount(1, $files);
            $source = file_get_contents($files[0]);
            self::assertSame(0, $app->console->run([$name], $output));
            self::assertSame($source, file_get_contents($files[0]));
            self::assertSame(0, $app->console->run(['migrate'], $output));
            [$table, $column] = match ($name) {
                'queue:install' => ['jobs', 'payload'],
                'cache:install' => ['cache', 'value'],
                default => ['auth_tokens', 'token_hash'],
            };
            self::assertTrue($app->container->get(\Crab\Contracts\Database\Schema::class)->hasColumn($table, $column));
        } finally {
            fclose($stream);
            $app->container->get(\Crab\Contracts\Database\Database::class)->close();
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
