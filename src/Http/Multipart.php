<?php

declare(strict_types=1);

namespace Crab\Http;

/**
 * Reads a multipart/form-data body into fields and uploaded files, from
 * the seekable resource the body is stored in. The body is read once,
 * through a small window, and a file is never held in memory: its
 * UploadedFile reads from the place in the body where the file lies.
 * Fields are kept in memory, so together they may only be so large.
 *
 * @internal
 */
final class Multipart
{
    /**
     * How much is read from the body at a time.
     */
    private const CHUNK = 65_536;
    /**
     * How long the headers of one part may be.
     */
    private const MAX_HEAD = 16_384;

    /**
     * The bytes of the body that are in memory, and where they start in it.
     */
    private string $window = '';
    private int $base = 0;

    /**
     * @param resource $stream
     */
    private function __construct(
        private readonly mixed $stream,
    ) {}

    /**
     * Names such as "photos[]" and "user[name]" nest as they do in PHP's
     * own request handling.
     *
     * @param resource $stream The body, seekable. It is read from its start, and the uploaded files go on reading from it afterwards.
     * @param int $maxFieldBytes How many bytes all the fields together may be; a file may be any size the body may.
     * @return array{array<string, mixed>, array<string, mixed>} The fields, and the UploadedFile tree.
     */
    public static function parse(
        string $contentType,
        mixed $stream,
        int $maxParts = 1000,
        int $maxFieldBytes = 1_048_576,
    ): array {
        if (preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentType, $match) !== 1) {
            throw new HttpException(400, 'The multipart body has no boundary.');
        }
        $delimiter = '--' . ($match[1] !== '' ? $match[1] : $match[2]);
        // parse_str() reads no more variables than this, so neither may a form have.
        $maxParts = min($maxParts, max(1, (int) ini_get('max_input_vars')));
        $body = new self($stream);
        $position = $body->find($delimiter, 0);
        if ($position === null) {
            throw self::malformed();
        }
        $position += strlen($delimiter);
        $fields = [];
        $names = [];
        $files = [];
        $fieldBytes = 0;
        for ($parts = 0;; ++$parts) {
            $next = $body->take($position, 2);
            if ($next === '--') {
                break;
            }
            if ($next !== "\r\n") {
                throw self::malformed();
            }
            if ($parts === $maxParts) {
                throw new HttpException(413, 'The multipart body has too many parts.');
            }
            $position += 2;
            // The part's headers end at the first empty line, and its content at the next delimiter.
            $content = $body->find("\r\n\r\n", $position, self::MAX_HEAD);
            if ($content === null) {
                throw self::malformed();
            }
            $head = $body->take($position, $content - $position);
            if (
                str_contains($head, $delimiter)
                || preg_match('/^content-disposition:[ \t]*form-data;(.*)$/mi', $head, $disposition) !== 1
                || preg_match('/\bname="((?:[^"\\\\]|\\\\.)*)"/', $disposition[1], $name) !== 1
            ) {
                throw self::malformed();
            }
            $start = $content + 4;
            // An empty part ends with the line break that ended its headers.
            $end = $body->find("\r\n" . $delimiter, $start - 2);
            if ($end === null) {
                throw self::malformed();
            }
            $size = max(0, $end - $start);
            $key = urlencode(stripslashes($name[1]));
            if (preg_match('/\bfilename="((?:[^"\\\\]|\\\\.)*)"/', $disposition[1], $filename) === 1) {
                $type = preg_match('/^content-type:[ \t]*(.+)$/mi', $head, $match) === 1 ? trim($match[1]) : null;
                $names[] = $key . '=' . count($files);
                // A file input left empty arrives as a part with no file name.
                $files[] =
                    $filename[1] === '' && $size === 0
                        ? new UploadedFile(new Stream(''), 0, UPLOAD_ERR_NO_FILE, '', $type)
                        : new UploadedFile(
                            new StreamSlice($stream, $start, $size),
                            $size,
                            UPLOAD_ERR_OK,
                            stripslashes($filename[1]),
                            $type,
                        );
            } else {
                $fieldBytes += $size;
                if ($fieldBytes > $maxFieldBytes) {
                    throw new HttpException(413, 'The form\'s fields are too large. Send large content as a file.');
                }
                $fields[] = $key . '=' . urlencode($body->take($start, $size));
            }
            $position = $end + 2 + strlen($delimiter);
        }
        @parse_str(implode('&', $fields), $parsed);
        @parse_str(implode('&', $names), $tree);
        array_walk_recursive($tree, static function (mixed &$index) use ($files): void {
            $index = $files[(int) $index];
        });
        return [$parsed, $tree];
    }

    /**
     * Where the text next occurs at or after a position, or null when it
     * does not. A search starts no earlier than the one before it, so
     * the body is read through once however many parts it has, and what
     * has been searched is let go of.
     *
     * @param int|null $within How far past the position the text may start; null for anywhere.
     */
    private function find(string $needle, int $from, ?int $within = null): ?int
    {
        $this->forgetBefore($from);
        $keep = strlen($needle) - 1;
        $searched = 0;
        while (true) {
            $found = strpos($this->window, $needle, $searched);
            if ($found !== false) {
                $position = $this->base + $found;
                return $within !== null && ($position - $from) > $within ? null : $position;
            }
            if ($within !== null && ($this->base + strlen($this->window) - $from) > ($within + strlen($needle))) {
                return null;
            }
            if ($within === null && strlen($this->window) > $keep) {
                // Nothing before the last few bytes can begin a match, and a large file is not to be kept.
                $this->forgetBefore($this->base + strlen($this->window) - $keep);
            }
            // The end of what was searched may hold the start of a match.
            $searched = max(0, strlen($this->window) - $keep);
            if (!$this->readMore()) {
                return null;
            }
        }
    }

    /**
     * The bytes at a position, fewer when the body ends first.
     */
    private function take(int $from, int $length): string
    {
        if ($length < 1) {
            return '';
        }
        if ($from >= $this->base) {
            while (($this->base + strlen($this->window)) < ($from + $length) && $this->readMore()) {
                // Reads on until the window holds them.
            }
            return substr($this->window, $from - $this->base, $length);
        }
        // Already let go of: read again from where it lies.
        if (fseek($this->stream, $from) === -1) {
            throw new \RuntimeException('Cannot seek in the request body.');
        }
        return (string) fread($this->stream, $length);
    }

    private function forgetBefore(int $position): void
    {
        if ($position <= $this->base) {
            return;
        }
        $drop = $position - $this->base;
        $this->window = $drop >= strlen($this->window) ? '' : substr($this->window, $drop);
        $this->base = $position;
    }

    private function readMore(): bool
    {
        // Sought every time: the uploaded files and take() read from the same resource.
        if (fseek($this->stream, $this->base + strlen($this->window)) === -1) {
            throw new \RuntimeException('Cannot seek in the request body.');
        }
        $bytes = fread($this->stream, self::CHUNK);
        if ($bytes === false || $bytes === '') {
            return false;
        }
        $this->window .= $bytes;
        return true;
    }

    private static function malformed(): HttpException
    {
        return new HttpException(400, 'The multipart body is malformed.');
    }
}
