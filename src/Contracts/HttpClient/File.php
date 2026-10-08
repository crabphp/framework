<?php

declare(strict_types=1);

namespace Crab\Contracts\HttpClient;

/**
 * A file to send with a request, as a form's file field:
 *
 *     $http->post($url, files: ['avatar' => File::fromPath('/tmp/me.png')]);
 *     $http->post($url, files: ['report' => File::fromContents($csv, 'report.csv', 'text/csv')]);
 */
final readonly class File
{
    private function __construct(
        public ?string $path,
        public ?string $contents,
        public string $name,
        public string $type,
    ) {
    }

    /**
     * A file on disk. It is read when the request is sent, never into memory whole.
     *
     * @param string|null $name The name the other server is told; the file's own when left out.
     */
    public static function fromPath(string $path, ?string $name = null, string $type = 'application/octet-stream'): self
    {
        return new self($path, null, $name ?? basename($path), $type);
    }

    /**
     * Content already in hand, sent under a file name.
     */
    public static function fromContents(string $contents, string $name, string $type = 'application/octet-stream'): self
    {
        return new self(null, $contents, $name, $type);
    }
}
