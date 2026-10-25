<?php

declare(strict_types=1);

namespace Crab\Storage;

use Crab\Contracts\Storage\Disk;
use Crab\Contracts\Storage\File;
use Crab\Contracts\Storage\FileNotFound;
use Crab\Contracts\Storage\Path;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A disk in this process's memory, for tests: what is written is kept
 * until the disk is gone, and its URLs, signed ones too, name the path
 * and the time without reaching anything:
 *
 *     memory://avatars/7.png
 *     memory://avatars/7.png?expires=1767225600
 *     memory://avatars/7.png?expires=1767225600&upload=image%2Fpng
 */
final class MemoryDisk implements Disk
{
    /**
     * @var array<string, array{string, ?string, \DateTimeImmutable}>
     */
    private array $files = [];

    public function __construct(
        private readonly StreamFactoryInterface $streams,
        private readonly ?ClockInterface $clock = null,
    ) {}

    public function write(string $path, string|StreamInterface $contents, ?string $type = null): void
    {
        if ($contents instanceof StreamInterface) {
            if ($contents->isSeekable()) {
                $contents->rewind();
            }
            $contents = $contents->getContents();
        }
        $this->files[Path::file($path)] = [$contents, $type, $this->clock?->now() ?? new \DateTimeImmutable()];
    }

    public function read(string $path): StreamInterface
    {
        return $this->streams->createStream($this->contents($path));
    }

    public function contents(string $path): string
    {
        return ($this->files[Path::file($path)] ?? throw new FileNotFound($path))[0];
    }

    public function exists(string $path): bool
    {
        return isset($this->files[Path::file($path)]);
    }

    public function file(string $path): ?File
    {
        $path = Path::file($path);
        if (!isset($this->files[$path])) {
            return null;
        }
        [$contents, $type, $modified] = $this->files[$path];
        return new File($path, strlen($contents), $modified, $type);
    }

    public function delete(string $path): void
    {
        unset($this->files[Path::file($path)]);
    }

    public function copy(string $from, string $to): void
    {
        $file = $this->files[Path::file($from)] ?? throw new FileNotFound($from);
        $this->write($to, $file[0], $file[1]);
    }

    public function move(string $from, string $to): void
    {
        $this->copy($from, $to);
        if (Path::file($from) !== Path::file($to)) {
            $this->delete($from);
        }
    }

    public function files(string $prefix = ''): iterable
    {
        $prefix = Path::prefix($prefix);
        $paths = array_filter(array_keys($this->files), static fn(string $path): bool => str_starts_with(
            $path,
            $prefix,
        ));
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $file = $this->file($path);
            if ($file !== null) {
                yield $file;
            }
        }
    }

    public function url(string $path): string
    {
        return 'memory://' . Path::file($path);
    }

    public function temporaryUrl(string $path, \DateTimeInterface $expires): string
    {
        return $this->url($path) . '?expires=' . $expires->getTimestamp();
    }

    public function temporaryUploadUrl(string $path, \DateTimeInterface $expires, ?string $type = null): string
    {
        return $this->temporaryUrl($path, $expires) . '&upload=' . rawurlencode($type ?? '');
    }
}
