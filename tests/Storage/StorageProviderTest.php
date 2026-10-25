<?php

declare(strict_types=1);

namespace Crab\Tests\Storage;

use Crab\App;
use Crab\Config\Config;
use Crab\Contracts\Storage\Disk;
use Crab\Contracts\Storage\Drivers;
use Crab\Contracts\Storage\Storage;
use Crab\Storage\LocalDisk;
use Crab\Storage\MemoryDisk;
use Crab\Storage\StorageManager;
use Crab\Storage\StorageProvider;
use PHPUnit\Framework\TestCase;

final class StorageProviderTest extends TestCase
{
    public function testAnApplicationHasALocalDiskInItsStorageFolderWithNothingConfigured(): void
    {
        $base = sys_get_temp_dir() . '/crab-app-' . bin2hex(random_bytes(6));
        $app = self::app([], $base);

        $disk = $app->container->get(Disk::class);
        self::assertInstanceOf(LocalDisk::class, $disk);
        self::assertSame($disk, $app->container->get(Storage::class)->disk('local'));
        $disk->write('hello.txt', 'hi');
        self::assertSame('hi', (string) file_get_contents($base . '/storage/files/hello.txt'));

        unlink($base . '/storage/files/hello.txt');
        rmdir($base . '/storage/files');
        rmdir($base . '/storage');
        rmdir($base);
    }

    public function testDisksAreNamedAndMadeByTheirDriver(): void
    {
        $app = self::app([
            'storage' => [
                'default' => 'uploads',
                'disks' => [
                    'uploads' => ['driver' => 'memory'],
                    'public' => ['driver' => 'local', 'root' => '/srv/files'],
                ],
            ],
        ]);
        $storage = $app->container->get(Storage::class);

        self::assertInstanceOf(MemoryDisk::class, $storage->disk());
        self::assertSame($storage->disk(), $storage->disk('uploads'), 'A disk is made once.');
        self::assertInstanceOf(LocalDisk::class, $storage->disk('public'));
        self::assertSame([], $app->container->get(StorageManager::class)->problems());

        $this->expectExceptionMessage(
            'There is no disk named "backups" in storage.disks; the disks are "local", "uploads", "public".',
        );
        $storage->disk('backups');
    }

    public function testAPackageAddsADriver(): void
    {
        $app = self::app(['storage' => ['disks' => ['cloud' => ['driver' => 'bucket', 'name' => 'b']]]]);
        $app->container->get(Drivers::class)->add(
            'bucket',
            ['name'],
            static fn(array $settings, string $name): Disk => new MemoryDisk(new \Crab\Http\Factory()),
        );

        self::assertInstanceOf(MemoryDisk::class, $app->container->get(Storage::class)->disk('cloud'));
    }

    public function testAMisspeltDriverOrSettingOrDefaultIsSaidBeforeAnyFileIsWritten(): void
    {
        $app = self::app([
            'storage' => [
                'default' => 'uplods',
                'disks' => ['cloud' => ['driver' => 's3'], 'files' => ['driver' => 'local', 'rot' => '/srv']],
            ],
        ]);
        $manager = $app->container->get(StorageManager::class);

        self::assertSame(
            [
                'The disk "cloud" has the driver "s3", and the drivers are "local", "memory". For "s3", install crabphp/s3 and register its S3Provider after the framework\'s providers.',
                'storage.default is "uplods", which is not one of storage.disks.',
            ],
            $manager->problems(),
        );
        try {
            new StorageProvider()->start(
                new \Crab\Engine\Process\Worker(
                    new \Crab\Engine\EventLoop\StreamSelectEventLoop(),
                    new \Crab\Engine\Crab('web'),
                    0,
                    ['tcp' => [], 'udp' => []],
                    1.0,
                ),
                $app->container,
            );
            self::fail('A worker started with a disk no driver makes.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('"cloud" has the driver "s3"', $error->getMessage());
        }

        $this->expectExceptionMessage(
            'The disk "files" has settings its driver does not know: "rot"; it knows "root", "url".',
        );
        $manager->disk('files');
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function app(array $config, ?string $base = null): App
    {
        $app = new App(new Config($config), basePath: $base);
        $app->register(new StorageProvider());
        return $app;
    }
}
