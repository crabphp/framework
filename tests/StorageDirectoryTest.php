<?php

declare(strict_types=1);

namespace Crab\Tests;

use Crab\App;
use Crab\Config\Env;
use Crab\Contracts\Cache\Store;
use Crab\Contracts\Database\Database;
use Crab\ServerFiles;
use PHPUnit\Framework\TestCase;

/**
 * Where an application writes: "storage" in its folder unless moved, by
 * withStorage() or CRAB_STORAGE, and beside the phar it is packed into,
 * as is its .env, since nothing can be written inside a phar.
 */
final class StorageDirectoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crab-storage-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/config', 0o777, true);
        putenv('CRAB_STORAGE');
    }

    protected function tearDown(): void
    {
        putenv('CRAB_STORAGE');
        // A loaded .env lasts for the process: empty is unset, so no later test is moved.
        file_put_contents($this->directory . '/reset.env', "CRAB_STORAGE=\n");
        Env::load($this->directory . '/reset.env');
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testItIsStorageInTheApplicationsFolderUnlessMoved(): void
    {
        $app = App::configure($this->directory)->create();

        self::assertSame($this->directory . '/storage', $app->storagePath());
        self::assertSame($this->directory . '/storage/logs/app.log', $app->path('storage/logs/app.log'));
        self::assertSame($this->directory . '/routes/web.php', $app->path('routes/web.php'));
        self::assertSame($this->directory . '/storage/crab.pid', $app->container->get(ServerFiles::class)->pid);
    }

    public function testWithStorageMovesEverythingTheFrameworkWritesAndWhatConfigurationNames(): void
    {
        $elsewhere = $this->directory . '/elsewhere';
        file_put_contents($this->directory . '/config/log.php', "<?php return ['path' => 'storage/logs/app.log'];");
        file_put_contents(
            $this->directory . '/config/database.php',
            "<?php return ['connections' => ['main' => ['driver' => 'sqlite', 'database' => storage_path('database.sqlite')]]];",
        );
        file_put_contents($this->directory . '/config/cache.php', "<?php return ['store' => 'file'];");

        $app = App::configure($this->directory)->withStorage($elsewhere)->create();

        self::assertSame($elsewhere, $app->config->get('app.storage'));
        self::assertSame($elsewhere . '/database.sqlite', $app->config->get('database.connections.main.database'));
        self::assertSame($elsewhere . '/logs/app.log', $app->path((string) $app->config->get('log.path')));
        $files = $app->container->get(ServerFiles::class);
        self::assertSame([$elsewhere . '/crab.pid', $elsewhere . '/logs/server.log', $elsewhere . '/crab.stats'], [
            $files->pid,
            $files->log,
            $files->stats,
        ]);
        $app->container->get(Store::class)->set('k', 'v', null);
        self::assertDirectoryExists($elsewhere . '/cache');
        self::assertSame(
            $elsewhere . '/x',
            storage_path('x'),
            'The helper reads the current application once it exists.',
        );
    }

    public function testASqliteFileInAStorageDirectoryNotMadeYetIsMade(): void
    {
        file_put_contents(
            $this->directory . '/config/database.php',
            "<?php return ['connections' => ['main' => ['driver' => 'sqlite', 'database' => storage_path('data/app.sqlite')]]];",
        );
        $app = App::configure($this->directory)
            ->withStorage($this->directory . '/fresh')
            ->create();

        self::assertSame(['one' => 1], $app->container->get(Database::class)->first('select 1 as one'));
        self::assertFileExists($this->directory . '/fresh/data/app.sqlite');
    }

    public function testCrabStorageMovesItWithoutAChangeToTheCodeRelativeToTheWorkingDirectory(): void
    {
        file_put_contents($this->directory . '/.env', "CRAB_STORAGE=from-env\n");
        $cwd = getcwd();
        chdir($this->directory);
        try {
            $app = App::configure($this->directory)->withEnv('.env')->create();
            $files = ServerFiles::in('/no/such/app');
        } finally {
            chdir((string) $cwd);
        }

        self::assertSame($this->directory . '/from-env', $app->storagePath());
        self::assertSame($this->directory . '/from-env/crab.pid', $files->pid, 'Found without the application too.');
        self::assertSame(
            $this->directory . '/given',
            App::configure($this->directory)->withStorage('given')->create()->storagePath(),
            'withStorage() comes first.',
        );
    }

    public function testAnApplicationPackedIntoAPharWritesAndReadsItsEnvBesideIt(): void
    {
        if (!class_exists(\Phar::class)) {
            self::markTestSkipped('The phar extension is not loaded.');
        }
        $source = $this->directory . '/source';
        mkdir($source . '/config', 0o777, true);
        file_put_contents(
            $source . '/config/database.php',
            "<?php return ['connections' => ['main' => ['driver' => 'sqlite', 'database' => storage_path('database.sqlite')]]];",
        );
        file_put_contents($source . '/main.php', '<?php
            require '
        . var_export(dirname(__DIR__) . '/vendor/autoload.php', true)
        . ';
            $app = Crab\App::configure(__DIR__)->withEnv(".env")->withConfig("config")->create();
            echo json_encode([$app->storagePath(), $app->config->get("database.connections.main.database"), $app->config->get("app.name"), $app->path("config")]);
        ');
        $bin = $this->directory . '/bin';
        mkdir($bin);
        file_put_contents($bin . '/.env', "APP_NAME=Packed\n");
        file_put_contents($source . '/config/app.php', "<?php return ['name' => env('APP_NAME', 'Crab')];");
        $build = sprintf(
            '%s -d phar.readonly=0 -r %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(
                '$p = new Phar('
                . var_export($bin . '/app.phar', true)
                . '); $p->buildFromDirectory('
                . var_export($source, true)
                . '); $p->setStub($p->createDefaultStub("main.php"));',
            ),
        );
        exec($build, $out, $status);
        self::assertSame(0, $status, implode("\n", $out));

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin . '/app.phar') . ' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame(
            [$bin . '/storage', $bin . '/storage/database.sqlite', 'Packed', 'phar://' . $bin . '/app.phar/config'],
            json_decode((string) end($output), true),
        );
    }
}
