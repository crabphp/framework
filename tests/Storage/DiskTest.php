<?php

declare(strict_types=1);

namespace Crab\Tests\Storage;

use Crab\Contracts\Storage\Disk;
use Crab\Contracts\Storage\File;
use Crab\Contracts\Storage\FileNotFound;
use Crab\Contracts\Storage\InvalidPath;
use Crab\Http\Factory;
use Crab\Http\Stream;
use Crab\Storage\LocalDisk;
use Crab\Storage\MemoryDisk;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What every disk does, the same on each.
 */
final class DiskTest extends TestCase
{
    private static string $root;

    protected function setUp(): void
    {
        self::$root = sys_get_temp_dir() . '/crab-disk-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir(self::$root)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::$root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir((string) $file) : unlink((string) $file);
        }
        rmdir(self::$root);
    }

    /**
     * @return iterable<string, array{\Closure(): Disk}>
     */
    public static function disks(): iterable
    {
        yield 'local' => [static fn(): Disk => new LocalDisk(self::$root, new Factory())];
        yield 'memory' => [static fn(): Disk => new MemoryDisk(new Factory())];
    }

    /**
     * @param \Closure(): Disk $disk
     */
    #[DataProvider('disks')]
    public function testAFileIsWrittenReadAndReplaced(\Closure $disk): void
    {
        $disk = $disk();
        self::assertFalse($disk->exists('avatars/7.png'));
        self::assertNull($disk->file('avatars/7.png'));

        $disk->write('avatars/7.png', 'first');
        self::assertTrue($disk->exists('avatars/7.png'));
        self::assertSame('first', $disk->contents('avatars/7.png'));

        $disk->write('avatars/7.png', new Stream(str_repeat('b', 200_000)));
        self::assertSame(200_000, strlen((string) $disk->read('avatars/7.png')));
        $file = $disk->file('avatars/7.png');
        self::assertInstanceOf(File::class, $file);
        self::assertSame(['avatars/7.png', 200_000], [$file->path, $file->size]);
        self::assertEqualsWithDelta(time(), $file->modified->getTimestamp(), 5);

        // Bytes come back as they went in.
        $bytes = random_bytes(4096) . "\0\r\n";
        $disk->write('raw.bin', $bytes);
        self::assertSame($bytes, $disk->contents('raw.bin'));
    }

    /**
     * @param \Closure(): Disk $disk
     */
    #[DataProvider('disks')]
    public function testAMissingFileIsSaidSo(\Closure $disk): void
    {
        $disk = $disk();
        foreach ([
            static fn() => $disk->read('nope.txt'),
            static fn() => $disk->contents('nope.txt'),
            static fn() => $disk->copy('nope.txt', 'other.txt'),
            static fn() => $disk->move('nope.txt', 'other.txt'),
        ] as $call) {
            try {
                $call();
                self::fail('A missing file was not said to be missing.');
            } catch (FileNotFound $missing) {
                self::assertSame('nope.txt', $missing->path);
            }
        }
        $disk->delete('nope.txt');
        self::assertFalse($disk->exists('other.txt'));
    }

    /**
     * @param \Closure(): Disk $disk
     */
    #[DataProvider('disks')]
    public function testFilesAreCopiedMovedAndDeleted(\Closure $disk): void
    {
        $disk = $disk();
        $disk->write('staged/upload', 'bytes');
        $disk->copy('staged/upload', 'kept/copy');
        $disk->move('staged/upload', 'kept/final');

        self::assertFalse($disk->exists('staged/upload'));
        self::assertSame(['bytes', 'bytes'], [$disk->contents('kept/copy'), $disk->contents('kept/final')]);
        $disk->delete('kept/copy');
        self::assertFalse($disk->exists('kept/copy'));
    }

    /**
     * @param \Closure(): Disk $disk
     */
    #[DataProvider('disks')]
    public function testFilesAreListedByPrefixInOrderOfPath(\Closure $disk): void
    {
        $disk = $disk();
        self::assertSame([], self::paths($disk->files()));
        foreach (['b/2.txt', 'a/1.txt', 'a/sub/3.txt', 'ab.txt', 'c.txt'] as $path) {
            $disk->write($path, $path);
        }

        self::assertSame(['a/1.txt', 'a/sub/3.txt', 'ab.txt', 'b/2.txt', 'c.txt'], self::paths($disk->files()));
        self::assertSame(['a/1.txt', 'a/sub/3.txt'], self::paths($disk->files('a/')));
        self::assertSame(['a/1.txt', 'a/sub/3.txt', 'ab.txt'], self::paths($disk->files('a')));
        self::assertSame(['a/sub/3.txt'], self::paths($disk->files('a/s')));
        self::assertSame([], self::paths($disk->files('z/')));
        self::assertSame([7, 11], array_map(static fn(File $file): int => $file->size, [...$disk->files('a/')]));
    }

    /**
     * @param \Closure(): Disk $disk
     */
    #[DataProvider('disks')]
    public function testAPathThatCouldLeaveTheDiskIsRefused(\Closure $disk): void
    {
        $disk = $disk();
        foreach (['', '/etc/passwd', '../secret', 'a/../../b', 'a//b', './a', 'a/', "a\0b", 'a\\b'] as $path) {
            try {
                $disk->write($path, 'x');
                self::fail("\"{$path}\" was taken as a path.");
            } catch (InvalidPath) {
                self::assertTrue(true);
            }
        }
        foreach (['../', '/a', 'a//'] as $prefix) {
            try {
                iterator_to_array($disk->files($prefix));
                self::fail("\"{$prefix}\" was taken as a prefix.");
            } catch (InvalidPath) {
                self::assertTrue(true);
            }
        }
    }

    public function testALocalDiskWritesWholeFilesAndLeavesNoPartOfOneBehind(): void
    {
        $disk = new LocalDisk(self::$root, new Factory(), 'https://cdn.example.com/files/');
        $disk->write('reports/q1 & q2.csv', 'a,b');

        self::assertSame(['q1 & q2.csv'], array_values(array_diff(scandir(self::$root . '/reports'), ['.', '..'])));
        self::assertSame('https://cdn.example.com/files/reports/q1%20%26%20q2.csv', $disk->url('reports/q1 & q2.csv'));
        // A part left by a write that was cut short is not a file.
        file_put_contents(self::$root . '/reports/.crab-partial-0123456789ab', 'half');
        self::assertSame(['reports/q1 & q2.csv'], self::paths($disk->files()));

        try {
            $disk->temporaryUrl('reports/q1 & q2.csv', new \DateTimeImmutable('+1 hour'));
            self::fail('A local disk signed a URL.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('cannot sign', $error->getMessage());
        }
        $this->expectExceptionMessage('no "url" setting');
        new LocalDisk(self::$root, new Factory())->url('reports/q1 & q2.csv');
    }

    public function testAMemoryDiskKeepsTheTypeAndNamesItsUrls(): void
    {
        $disk = new MemoryDisk(new Factory());
        $disk->write('a.png', 'png', 'image/png');
        $expires = new \DateTimeImmutable('@1767225600');

        self::assertSame('image/png', $disk->file('a.png')?->type);
        self::assertSame('memory://a.png?expires=1767225600', $disk->temporaryUrl('a.png', $expires));
        self::assertSame('memory://a.png?expires=1767225600&upload=image%2Fpng', $disk->temporaryUploadUrl(
            'a.png',
            $expires,
            'image/png',
        ));
    }

    /**
     * @param iterable<File> $files
     * @return list<string>
     */
    private static function paths(iterable $files): array
    {
        $paths = [];
        foreach ($files as $file) {
            $paths[] = $file->path;
        }
        return $paths;
    }
}
