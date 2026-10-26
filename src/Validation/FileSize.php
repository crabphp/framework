<?php

declare(strict_types=1);

namespace Crab\Validation;

use Crab\Contracts\Validation\Rule;
use Psr\Http\Message\UploadedFileInterface;

/**
 * How large an uploaded file may be, in bytes: #[FileSize(max: 10 * 1024 * 1024)].
 * It is checked after the file has arrived; what a request may carry at
 * all is the server's max_body setting.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class FileSize implements Rule
{
    public function __construct(
        public ?int $min = null,
        public ?int $max = null,
    ) {
        if ($min === null && $max === null) {
            throw new \LogicException('FileSize needs a min, a max or both.');
        }
    }

    public function check(mixed $value): ?string
    {
        if (!$value instanceof UploadedFileInterface) {
            return 'must be a file';
        }
        $size = $value->getSize() ?? $value->getStream()->getSize();
        if ($size === null) {
            return 'must be a file whose size is known';
        }
        if ($this->min !== null && $size < $this->min) {
            return 'must be at least ' . self::bytes($this->min);
        }
        if ($this->max !== null && $size > $this->max) {
            return 'must be at most ' . self::bytes($this->max);
        }
        return null;
    }

    /**
     * A size as a person would write it: "10 MB", "512 KB", "300 bytes".
     */
    private static function bytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_048_576 && ($bytes % 1_048_576) === 0 => ($bytes / 1_048_576) . ' MB',
            $bytes >= 1024 && ($bytes % 1024) === 0 => ($bytes / 1024) . ' KB',
            default => "{$bytes} bytes",
        };
    }
}
