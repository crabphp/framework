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
        $handle = @fopen($this->file($key), 'r');
        if ($handle === false) {
            return null;
        }
        try {
            // Shared with other readers; a writer has it alone, so half an entry is never read.
            flock($handle, LOCK_SH);
            $contents = (string) stream_get_contents($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        if ($contents === '') {
            return null;
        }
        [$expiresAt, $value] = self::parse($contents);
        // An expired entry is left where it is: deleting it here could delete the one
        // another worker has just written in its place. The next write takes its place.
        return $expiresAt !== null && $expiresAt <= $now ? null : $value;
    }

    public function set(string $key, string $value, ?int $expiresAt): void
    {
        $handle = $this->lock($this->file($key));
        try {
            self::write($handle, $value, $expiresAt);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function add(string $key, string $value, ?int $expiresAt, int $now): bool
    {
        $handle = $this->lock($this->file($key));
        try {
            $contents = (string) stream_get_contents($handle);
            if ($contents !== '') {
                [$existing] = self::parse($contents);
                if ($existing === null || $existing > $now) {
                    return false;
                }
            }
            self::write($handle, $value, $expiresAt);
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function increment(string $key, int $by, ?int $expiresAt, int $now): int
    {
        $handle = $this->lock($this->file($key));
        try {
            $entry = null;
            $contents = (string) stream_get_contents($handle);
            if ($contents !== '') {
                [$existing, $value] = self::parse($contents);
                if ($existing === null || $existing > $now) {
                    $entry = $value;
                }
            }
            $count = Counter::read($entry) + $by;
            self::write($handle, Counter::write($count), Counter::is($entry) ? $existing : $expiresAt);
            return $count;
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

    /**
     * Opens an entry's file for writing and holds it alone. Whoever reads
     * or writes the entry waits for the same lock, so of several callers
     * of add() one finds it empty.
     *
     * @return resource
     */
    private function lock(string $file): mixed
    {
        $this->prepare($file);
        for ($try = 0; $try < 5; ++$try) {
            $handle = @fopen($file, 'c+');
            if ($handle === false) {
                break;
            }
            flock($handle, LOCK_EX);
            clearstatcache(true, $file);
            // Deleted or cleared while this waited: the lock is on a file no one else will open. Start again.
            if ((fstat($handle)['ino'] ?? null) === (@stat($file)['ino'] ?? false)) {
                return $handle;
            }
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        throw new \RuntimeException("The cache could not write to {$this->directory}. Check that the directory can be written to.");
    }

    /**
     * @param resource $handle The entry's file, locked.
     */
    private static function write(mixed $handle, string $value, ?int $expiresAt): void
    {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, ($expiresAt ?? 0) . "\n" . $value);
        fflush($handle);
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
