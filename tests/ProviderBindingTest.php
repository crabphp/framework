<?php

declare(strict_types=1);

namespace Crab\Tests;

use Crab\App;
use Crab\Auth\AuthProvider;
use Crab\Auth\DatabaseTokenStore;
use Crab\Auth\PasswordHasher;
use Crab\Auth\TokenAuthProvider;
use Crab\Config\Config;
use Crab\Container\Container;
use Crab\Contracts\Auth\PasswordHasher as PasswordHasherContract;
use Crab\Contracts\Auth\TokenStore;
use Crab\Contracts\Config\Config as ConfigContract;
use Crab\Contracts\Database\Database as DatabaseContract;
use Crab\Contracts\Database\Migrator as MigratorContract;
use Crab\Contracts\Database\Schema as SchemaContract;
use Crab\Database\Database;
use Crab\Database\DatabaseProvider;
use Crab\Database\Migrator;
use Crab\Database\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderBindingTest extends TestCase
{
    /**
     * @return array<string, array{class-string, class-string}>
     */
    public static function coreServices(): array
    {
        return [
            'config' => [ConfigContract::class, Config::class],
            'console' => [\Crab\Contracts\Console\Console::class, \Crab\Console\Console::class],
            'validator' => [\Crab\Contracts\Validation\Validator::class, \Crab\Validation\Validator::class],
            'router' => [\Crab\Contracts\Routing\Router::class, \Crab\Routing\Router::class],
            'middleware' => [\Crab\Contracts\Http\MiddlewareStack::class, \Crab\Http\MiddlewareStack::class],
            'servers' => [\Crab\Contracts\Servers::class, \Crab\Servers::class],
        ];
    }

    #[DataProvider('coreServices')]
    public function testAnEagerCoreServiceFollowsItsContractReplacement(string $contract, string $concrete): void
    {
        $container = new App()->container;
        $original = $container->get($contract);
        self::assertSame($original, $container->get($concrete));

        $replacement = clone $original;
        $container->instance($contract, $replacement);

        self::assertSame($replacement, $container->get($concrete));
    }

    public function testCoreAliasesReadTheReplacementConfigurationAndCommands(): void
    {
        $container = new App()->container;
        $container->instance(ConfigContract::class, new Config(['replaced' => true]));
        $console = new \Crab\Console\Console();
        $console->add('example:run', static fn(): int => 0);
        $container->instance(\Crab\Contracts\Console\Console::class, $console);

        self::assertTrue($container->get(Config::class)->get('replaced'));
        self::assertTrue($container->get(\Crab\Console\Console::class)->has('example:run'));
    }

    /**
     * @return array<string, array{class-string, class-string}>
     */
    public static function services(): array
    {
        return [
            'database' => [DatabaseContract::class, Database::class],
            'schema' => [SchemaContract::class, Schema::class],
            'migrator' => [MigratorContract::class, Migrator::class],
            'passwords' => [PasswordHasherContract::class, PasswordHasher::class],
            'tokens' => [TokenStore::class, DatabaseTokenStore::class],
        ];
    }

    #[DataProvider('services')]
    public function testAConcreteServiceIsItsContractsInstanceAndFollowsItsReplacement(
        string $contract,
        string $concrete,
    ): void {
        $container = new Container();
        $container->instance(ConfigContract::class, new Config([
            'database' => ['connections' => ['main' => ['dsn' => 'sqlite::memory:']]],
        ]));
        new DatabaseProvider()->register($container);
        new AuthProvider()->register($container);
        new TokenAuthProvider()->register($container);

        $service = $container->get($contract);
        self::assertSame($service, $container->get($concrete));

        $replacement = clone $service;
        $container->instance($contract, $replacement);
        self::assertSame($replacement, $container->get($concrete));
    }
}
