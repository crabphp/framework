<?php

declare(strict_types=1);

namespace Crab\Contracts\Storage;

/**
 * The one check of a path, part of what a Disk promises: every disk,
 * the framework's and a package's, takes the same paths, and none can be
 * led outside its folder or prefix.
 */
final class Path
{
    /**
     * A file's path: relative parts joined by "/", none empty, "." or "..".
     *
     * @throws InvalidPath
     */
    public static function file(string $path): string
    {
        if ($path === '' || str_ends_with($path, '/')) {
            throw new InvalidPath("\"{$path}\" is not a file's path: name the file, as in \"avatars/7.png\".");
        }
        return self::checked($path);
    }

    /**
     * A prefix to list by: empty for everything, or the start of a path.
     *
     * @throws InvalidPath
     */
    public static function prefix(string $prefix): string
    {
        if ($prefix === '') {
            return '';
        }
        // A folder's prefix ends with "/"; what comes before must be a path.
        $folder = rtrim($prefix, '/');
        if ($folder === '' || (strlen($prefix) - strlen($folder)) > 1) {
            throw new InvalidPath("\"{$prefix}\" is not a prefix of a path.");
        }
        return self::checked($folder) . substr($prefix, strlen($folder));
    }

    private static function checked(string $path): string
    {
        if (str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")) {
            throw new InvalidPath("\"{$path}\" is not a relative path with \"/\" between its parts.");
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new InvalidPath("\"{$path}\" has an empty, \".\" or \"..\" part.");
            }
        }
        return $path;
    }
}
