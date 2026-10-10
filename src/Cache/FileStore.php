<?php

declare(strict_types=1);

namespace Crab\Cache;

use Crab\Contracts\Cache\Store;

/**
 * Entries kept as files in a directory, one per key, shared by every
 * worker on the machine. The directory is made when the first entry is
 * written. A file holds the time it expires on its first line and the
 * entry after it; it is named after a hash of the key, so any key is a
 * safe file name.
 */
final readonly class FileStore implements Store
{
    public function __construct(private string $directory)
    {
        if ($directory === '') {
            throw new \InvalidArgumentException('A file cache needs a directory to keep its entries in.');
        }
    }

    public function get(string $key, int $now): ?string
    {
        $file = $this->file($key);
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return null;
        }
        [$expiresAt, $value] = self::parse($contents);
        if ($expiresAt !== null && $expiresAt <= $now) {
            @unlink($file);
            return null;
        }
        return $value;
    }

    public function set(string $key, string $value, ?int $expiresAt): void
    {
        $file = $this->file($key);
        $this->prepare($file);
        // Written beside it and moved into place, so that a reader sees the old entry or the new one, never half of one.
        $temporary = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($temporary, ($expiresAt ?? 0) . "\n" . $value) === false || !@rename($temporary, $file)) {
            @unlink($temporary);
            throw new \RuntimeException("The cache could not write to {$this->directory}. Check that the directory can be written to.");
        }
    }

    public function add(string $key, string $value, ?int $expiresAt, int $now): bool
    {
        $file = $this->file($key);
        $this->prepare($file);
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("The cache could not write to {$this->directory}. Check that the directory can be written to.");
        }
        try {
            // Held while reading and writing, so that of several callers one sees it empty.
            flock($handle, LOCK_EX);
            $contents = (string) stream_get_contents($handle);
            if ($contents !== '') {
                [$existing] = self::parse($contents);
                if ($existing === null || $existing > $now) {
                    return false;
                }
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, ($expiresAt ?? 0) . "\n" . $value);
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function delete(string $key): void
    {
        @unlink($this->file($key));
    }

    public function clear(): void
    {
        // Only what this store wrote: two-letter folders of hash-named files.
        foreach (glob($this->directory . '/[0-9a-f][0-9a-f]', GLOB_ONLYDIR) ?: [] as $folder) {
            foreach (glob($folder . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($folder);
        }
    }

    private function file(string $key): string
    {
        $hash = sha1($key);
        return $this->directory . '/' . substr($hash, 0, 2) . '/' . $hash;
    }

    private function prepare(string $file): void
    {
        $folder = dirname($file);
        if (!is_dir($folder) && !@mkdir($folder, 0o775, true) && !is_dir($folder)) {
            throw new \RuntimeException("The cache directory {$folder} cannot be created. Check that {$this->directory} can be written to.");
        }
    }

    /**
     * @return array{?int, string} When the entry expires, null for never, and the entry.
     */
    private static function parse(string $contents): array
    {
        $break = strpos($contents, "\n");
        if ($break === false) {
            // Not a file this store wrote whole; treated as expired.
            return [0, ''];
        }
        $expiresAt = (int) substr($contents, 0, $break);
        return [$expiresAt === 0 ? null : $expiresAt, substr($contents, $break + 1)];
    }
}
