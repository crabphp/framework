<?php

declare(strict_types=1);

namespace Crab\Validation;

use Crab\Contracts\Validation\Rule;
use Psr\Http\Message\UploadedFileInterface;

/**
 * What kind of file an upload may be, read from its content and never
 * from what the client says it is: #[MediaType('image/png', 'image/jpeg')],
 * or #[MediaType('image/*')] for any image. It needs PHP's fileinfo
 * extension.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class MediaType implements Rule
{
    /**
     * How much of the file is read to tell its type.
     */
    private const SNIFF_BYTES = 65_536;

    /**
     * @var list<string>
     */
    public array $types;

    public function __construct(string ...$types)
    {
        if ($types === []) {
            throw new \LogicException('MediaType needs at least one type, such as \'image/png\' or \'image/*\'.');
        }
        foreach ($types as $type) {
            if (preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/(?:\*|[a-z0-9][a-z0-9!#$&^_.+-]*)$~D', $type) !== 1) {
                throw new \LogicException(
                    "MediaType is given \"{$type}\", which is not a media type; write it as 'image/png' or 'image/*', in lower case.",
                );
            }
        }
        if (!class_exists(\finfo::class)) {
            throw new \LogicException(
                "MediaType reads a file's content with PHP's fileinfo extension, which is not enabled; enable it in php.ini.",
            );
        }
        $this->types = array_values($types);
    }

    public function check(mixed $value): ?string
    {
        if (!$value instanceof UploadedFileInterface) {
            return 'must be a file';
        }
        $type = self::detect($value);
        foreach ($this->types as $allowed) {
            $matches = str_ends_with($allowed, '/*')
                ? str_starts_with($type, substr($allowed, 0, -1))
                : $type === $allowed;
            if ($matches) {
                return null;
            }
        }
        return 'must be ' . implode(' or ', $this->types) . ", not {$type}";
    }

    /**
     * The type the file's first bytes say it is. The stream is left at
     * its start, so the file reads whole afterwards.
     */
    private static function detect(UploadedFileInterface $file): string
    {
        static $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $stream = $file->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $head = '';
        while (strlen($head) < self::SNIFF_BYTES && !$stream->eof()) {
            $chunk = $stream->read(self::SNIFF_BYTES - strlen($head));
            if ($chunk === '') {
                break;
            }
            $head .= $chunk;
        }
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $type = $finfo->buffer($head);
        return is_string($type) ? $type : 'application/octet-stream';
    }
}
