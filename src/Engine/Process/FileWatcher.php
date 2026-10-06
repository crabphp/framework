<?php

declare(strict_types=1);

namespace Crab\Engine\Process;

/**
 * Notices when source files change, by looking at them again: no
 * extension and no operating system service is needed. A directory is
 * watched for files of the given extensions, in it and below; a file is
 * watched as it is.
 */
final class FileWatcher
{
    /**
     * Folders that are never looked into: other people's code, version
     * control, and what the application itself writes.
     */
    private const IGNORED = ['vendor', 'node_modules', 'storage', '.git'];

    /**
     * What was last seen of every watched file, by path: its time of last
     * change, its size, and a hash of its content while that time is too
     * recent to tell two changes apart.
     *
     * @var array<string, array{int, int, string|null}>
     */
    private array $seen;

    /**
     * @param list<string> $paths Directories and files.
     * @param list<string> $extensions The files to watch inside a directory, by extension, without the dot.
     */
    public function __construct(private readonly array $paths, private readonly array $extensions = ['php'])
    {
        $this->seen = $this->look();
    }

    /**
     * @return list<string> The files added, changed or removed since the last call, or since the watcher was made.
     */
    public function changes(): array
    {
        $now = $this->look();
        $changed = [];
        foreach ($now as $path => [$time, $size, $hash]) {
            $before = $this->seen[$path] ?? null;
            if ($before === null || $before[0] !== $time || $before[1] !== $size || ($hash !== null && $before[2] !== null && $before[2] !== $hash)) {
                $changed[] = $path;
            }
        }
        foreach ($this->seen as $path => $state) {
            if (!isset($now[$path])) {
                $changed[] = $path;
            }
        }
        $this->seen = $now;
        return $changed;
    }

    /**
     * @return int How many files are watched.
     */
    public function count(): int
    {
        return count($this->seen);
    }

    /**
     * @return array<string, array{int, int, string|null}>
     */
    private function look(): array
    {
        clearstatcache();
        $found = [];
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                $found[$path] = self::state($path);
            } elseif (is_dir($path)) {
                $this->lookInto($path, $found);
            }
        }
        return $found;
    }

    /**
     * @param array<string, array{int, int, string|null}> $found
     */
    private function lookInto(string $directory, array &$found): void
    {
        foreach (@scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_dir($path)) {
                if (!in_array($name, self::IGNORED, true)) {
                    $this->lookInto($path, $found);
                }
            } elseif (in_array(pathinfo($name, PATHINFO_EXTENSION), $this->extensions, true)) {
                $found[$path] = self::state($path);
            }
        }
    }

    /**
     * @return array{int, int, string|null}
     */
    private static function state(string $path): array
    {
        $time = (int) @filemtime($path);
        // A file's time is kept to the second. One saved twice within a second, to
        // the same size, looks unchanged by time and size, so while its time is
        // that recent its content is compared too.
        $hash = $time >= time() - 2 ? (string) @md5_file($path) : null;
        return [$time, (int) @filesize($path), $hash];
    }
}
