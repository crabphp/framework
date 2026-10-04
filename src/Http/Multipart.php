<?php

declare(strict_types=1);

namespace Crab\Http;

/**
 * Reads a multipart/form-data body into fields and uploaded files. The
 * whole body is in memory while it is read, and each file's content stays
 * in memory until its UploadedFile is released.
 *
 * @internal
 */
final class Multipart
{
    /**
     * Names such as "photos[]" and "user[name]" nest as they do in PHP's
     * own request handling.
     *
     * @return array{array<string, mixed>, array<string, mixed>} The fields, and the UploadedFile tree.
     */
    public static function parse(string $contentType, string $body, int $maxParts = 1000): array
    {
        if (preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentType, $match) !== 1) {
            throw new HttpException(400, 'The multipart body has no boundary.');
        }
        $delimiter = '--' . ($match[1] !== '' ? $match[1] : $match[2]);
        // parse_str() reads no more variables than this, so neither may a form have.
        $maxParts = min($maxParts, max(1, (int) ini_get('max_input_vars')));
        $position = strpos($body, $delimiter);
        if ($position === false) {
            throw self::malformed();
        }
        $position += strlen($delimiter);
        $fields = [];
        $names = [];
        $files = [];
        $length = strlen($body);
        for ($parts = 0; ; ++$parts) {
            if ($position + 2 > $length) {
                throw self::malformed();
            }
            if ($body[$position] === '-' && $body[$position + 1] === '-') {
                break;
            }
            if ($body[$position] !== "\r" || $body[$position + 1] !== "\n") {
                throw self::malformed();
            }
            if ($parts === $maxParts) {
                throw new HttpException(413, 'The multipart body has too many parts.');
            }
            $position += 2;
            $end = strpos($body, "\r\n" . $delimiter, $position);
            $content = strpos($body, "\r\n\r\n", $position);
            if ($end === false || $content === false || $content > $end) {
                throw self::malformed();
            }
            $head = substr($body, $position, $content - $position);
            if (preg_match('/^content-disposition:[ \t]*form-data;(.*)$/mi', $head, $disposition) !== 1
                || preg_match('/\bname="((?:[^"\\\\]|\\\\.)*)"/', $disposition[1], $name) !== 1) {
                throw self::malformed();
            }
            $value = substr($body, $content + 4, $end - $content - 4);
            $key = urlencode(stripslashes($name[1]));
            if (preg_match('/\bfilename="((?:[^"\\\\]|\\\\.)*)"/', $disposition[1], $filename) === 1) {
                $type = preg_match('/^content-type:[ \t]*(.+)$/mi', $head, $match) === 1 ? trim($match[1]) : null;
                $names[] = $key . '=' . count($files);
                // A file input left empty arrives as a part with no file name.
                $files[] = $filename[1] === '' && $value === ''
                    ? new UploadedFile(new Stream(''), 0, UPLOAD_ERR_NO_FILE, '', $type)
                    : new UploadedFile(new Stream($value), strlen($value), UPLOAD_ERR_OK, stripslashes($filename[1]), $type);
            } else {
                $fields[] = $key . '=' . urlencode($value);
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

    private static function malformed(): HttpException
    {
        return new HttpException(400, 'The multipart body is malformed.');
    }
}
