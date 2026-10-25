<?php

declare(strict_types=1);

namespace Crab\Contracts\Storage;

use Psr\Http\Message\StreamInterface;

/**
 * Where files are kept: a folder on this machine or a bucket on S3 or a
 * service that speaks its API. A path is relative, with "/" between its
 * parts, and the same on every disk:
 *
 *     $disk->write('avatars/7.png', $upload->getStream(), 'image/png');
 *     $stream = $disk->read('avatars/7.png');
 *     $url = $disk->temporaryUrl('avatars/7.png', $clock->now()->modify('+10 minutes'));
 *
 * A file is written whole or not at all; a reader never sees half of one.
 * A path that is absolute or climbs out with ".." throws InvalidPath. A
 * failure to reach the disk, or one it refuses, throws StorageException.
 */
interface Disk
{
    /**
     * Writes a file, replacing one at the same path. A stream is copied as
     * it is read, never into memory whole.
     *
     * @param string|null $type Its media type, such as image/png, for a disk that keeps one.
     */
    public function write(string $path, string|StreamInterface $contents, ?string $type = null): void;

    /**
     * The file's bytes as a stream, for one too large to hold in memory or
     * to pass on as a response body.
     *
     * @throws FileNotFound
     */
    public function read(string $path): StreamInterface;

    /**
     * The file's bytes, whole.
     *
     * @throws FileNotFound
     */
    public function contents(string $path): string;

    public function exists(string $path): bool;

    /**
     * What is known of the file, or null when there is none.
     */
    public function file(string $path): ?File;

    /**
     * Removes the file; one that is not there is no failure.
     */
    public function delete(string $path): void;

    /**
     * Copies a file to another path on this disk, replacing one there.
     *
     * @throws FileNotFound
     */
    public function copy(string $from, string $to): void;

    /**
     * Moves a file to another path on this disk, replacing one there.
     *
     * @throws FileNotFound
     */
    public function move(string $from, string $to): void;

    /**
     * Every file whose path starts with the prefix, in order of path, read
     * a page at a time as it is iterated. "avatars/" is a folder's files,
     * with those of the folders inside it.
     *
     * @return iterable<File>
     */
    public function files(string $prefix = ''): iterable;

    /**
     * Where anyone can download the file, from the disk's "url" setting.
     *
     * @throws \LogicException When the disk has no public address.
     */
    public function url(string $path): string;

    /**
     * Where the file can be downloaded until the given time, without being
     * public, by a URL that carries its own signature.
     *
     * @throws \LogicException When the disk cannot sign one, as a folder on this machine cannot.
     */
    public function temporaryUrl(string $path, \DateTimeInterface $expires): string;

    /**
     * Where a client can upload the file itself with PUT until the given
     * time, so that its bytes need not pass through the application.
     *
     * @param string|null $type The media type the upload must declare in its Content-Type.
     * @throws \LogicException When the disk cannot sign one, as a folder on this machine cannot.
     */
    public function temporaryUploadUrl(string $path, \DateTimeInterface $expires, ?string $type = null): string;
}
