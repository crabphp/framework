<?php

declare(strict_types=1);

namespace Crab\Storage;

use Crab\Contracts\Storage\Disk;
use Crab\Contracts\Storage\File;
use Crab\Contracts\Storage\FileNotFound;
use Crab\Contracts\Storage\Path;
use Crab\Contracts\Storage\StorageException;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A folder on this machine. A file is written beside its place and then
 * renamed into it, so a reader sees the old file or the new one, never
 * half of one. Reading and writing a local disk is fast enough to do in
 * a worker; a folder on a network mount is not, and belongs on a disk
 * that speaks to that service instead.
 */
final class LocalDisk implements Disk
{
    /**
     * Where a file is written before it is renamed into place.
     */
    private const PARTIAL = '.crab-partial-';

    private readonly string $root;

    /**
     * @param string $root The folder; made when the first file is written.
     * @param string|null $url Where the folder is served from, for url().
     */
    public function __construct(
        string $root,
        private readonly StreamFactoryInterface $streams,
        private readonly ?string $url = null,
    ) {
        $this->root = rtrim($root, '/');
    }

    public function write(string $path, string|StreamInterface $contents, ?string $type = null): void
    {
        $target = $this->place($path);
        $folder = dirname($target);
        if (!is_dir($folder) && !@mkdir($folder, 0o777, true) && !is_dir($folder)) {
            throw new StorageException("The folder for \"{$path}\" could not be made: " . self::lastError());
        }
        $partial = $folder . '/' . self::PARTIAL . bin2hex(random_bytes(6));
        $handle = @fopen($partial, 'xb');
        if ($handle === false) {
            throw new StorageException("\"{$path}\" could not be written: " . self::lastError());
        }
        try {
            if (is_string($contents)) {
                $written = fwrite($handle, $contents) === strlen($contents);
            } else {
                if ($contents->isSeekable()) {
                    $contents->rewind();
                }
                $written = true;
                while ($written && !$contents->eof()) {
                    $bytes = $contents->read(65_536);
                    $written = fwrite($handle, $bytes) === strlen($bytes);
                }
            }
            $written = $written && fflush($handle);
        } finally {
            fclose($handle);
        }
        if (!$written || !@rename($partial, $target)) {
            $error = self::lastError();
            @unlink($partial);
            throw new StorageException("\"{$path}\" could not be written: {$error}");
        }
    }

    public function read(string $path): StreamInterface
    {
        $file = $this->place($path);
        $handle = is_file($file) ? @fopen($file, 'rb') : false;
        if ($handle === false) {
            throw new FileNotFound($path);
        }
        return $this->streams->createStreamFromResource($handle);
    }

    public function contents(string $path): string
    {
        $file = $this->place($path);
        $contents = is_file($file) ? @file_get_contents($file) : false;
        if ($contents === false) {
            throw new FileNotFound($path);
        }
        return $contents;
    }

    public function exists(string $path): bool
    {
        return is_file($this->place($path));
    }

    public function file(string $path): ?File
    {
        $file = $this->place($path);
        clearstatcache(true, $file);
        $stat = is_file($file) ? @stat($file) : false;
        return $stat === false ? null : self::described(Path::file($path), $stat);
    }

    public function delete(string $path): void
    {
        $file = $this->place($path);
        if (is_file($file) && !@unlink($file) && is_file($file)) {
            throw new StorageException("\"{$path}\" could not be deleted: " . self::lastError());
        }
    }

    public function copy(string $from, string $to): void
    {
        $this->write($to, $this->read($from));
    }

    public function move(string $from, string $to): void
    {
        $source = $this->place($from);
        if (!is_file($source)) {
            throw new FileNotFound($from);
        }
        $target = $this->place($to);
        $folder = dirname($target);
        if (!is_dir($folder) && !@mkdir($folder, 0o777, true) && !is_dir($folder)) {
            throw new StorageException("The folder for \"{$to}\" could not be made: " . self::lastError());
        }
        if (!@rename($source, $target)) {
            throw new StorageException("\"{$from}\" could not be moved to \"{$to}\": " . self::lastError());
        }
    }

    public function files(string $prefix = ''): iterable
    {
        $prefix = Path::prefix($prefix);
        if (!is_dir($this->root)) {
            return;
        }
        // Only the folder the prefix is in need be walked.
        $slash = strrpos($prefix, '/');
        $start = $slash === false ? $this->root : $this->root . '/' . substr($prefix, 0, $slash);
        if (!is_dir($start)) {
            return;
        }
        $paths = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $start,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME,
        ));
        foreach ($walk as $file) {
            $path = substr((string) $file, strlen($this->root) + 1);
            if (str_starts_with($path, $prefix) && !str_starts_with(basename($path), self::PARTIAL)) {
                $paths[] = $path;
            }
        }
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $stat = @stat($this->root . '/' . $path);
            if ($stat !== false) {
                yield self::described($path, $stat);
            }
        }
    }

    public function url(string $path): string
    {
        if ($this->url === null) {
            throw new \LogicException(
                'This local disk has no "url" setting, so its files have no public address. Set "url" to where the folder is served from.',
            );
        }
        return (
            rtrim($this->url, '/') . '/' . implode('/', array_map(rawurlencode(...), explode('/', Path::file($path))))
        );
    }

    public function temporaryUrl(string $path, \DateTimeInterface $expires): string
    {
        throw new \LogicException(
            'A local disk cannot sign a temporary URL: serve the file from an action that checks who asks, or keep it on a disk that signs, such as S3.',
        );
    }

    public function temporaryUploadUrl(string $path, \DateTimeInterface $expires, ?string $type = null): string
    {
        throw new \LogicException(
            'A local disk cannot sign an upload URL: take the upload in an action, or keep the file on a disk that signs, such as S3.',
        );
    }

    private function place(string $path): string
    {
        return $this->root . '/' . Path::file($path);
    }

    /**
     * @param array<int|string, int> $stat
     */
    private static function described(string $path, array $stat): File
    {
        return new File($path, (int) $stat['size'], new \DateTimeImmutable('@' . (int) $stat['mtime']));
    }

    private static function lastError(): string
    {
        return error_get_last()['message'] ?? 'unknown error';
    }
}
