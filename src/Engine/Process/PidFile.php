<?php

declare(strict_types=1);

namespace Crab\Engine\Process;

/**
 * The file a server in the background writes its process id to, so that
 * a later command can find it. It holds the number and nothing else.
 */
final readonly class PidFile
{
    public function __construct(public string $path)
    {
    }

    /**
     * @return int|null The id in the file when a process of that id is alive; null when there is no file or what it names has gone.
     */
    public function pid(): ?int
    {
        $content = @file_get_contents($this->path);
        if ($content === false) {
            return null;
        }
        $pid = (int) trim($content);
        return $pid > 0 && self::alive($pid) ? $pid : null;
    }

    /**
     * Writes this process's id, replacing the file in one step so that a
     * reader never sees it half written.
     */
    public function write(): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException("The directory for the process id file, {$directory}, cannot be created.");
        }
        $temporary = $this->path . '.' . getmypid();
        if (@file_put_contents($temporary, getmypid() . "\n") === false || !@rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new \RuntimeException("The process id file {$this->path} cannot be written.");
        }
    }

    /**
     * Removes the file when it still names this process. A server that
     * was replaced leaves the file to the one that replaced it.
     */
    public function remove(): void
    {
        $content = @file_get_contents($this->path);
        if ($content !== false && (int) trim($content) === getmypid()) {
            @unlink($this->path);
        }
    }

    /**
     * Whether a process of this id exists, including one that belongs to
     * another user.
     */
    public static function alive(int $pid): bool
    {
        if (!function_exists('posix_kill')) {
            return true;
        }
        // Signal 0 is not sent; it only asks whether it could be.
        return posix_kill($pid, 0) || posix_get_last_error() === 1;
    }
}
