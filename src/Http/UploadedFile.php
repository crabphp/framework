<?php

declare(strict_types=1);

namespace Crab\Http;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A PSR-7 uploaded file. The client's file name and media type are what the
 * client claimed and must not be trusted.
 */
final class UploadedFile implements UploadedFileInterface
{
    private bool $moved = false;

    public function __construct(
        private readonly StreamInterface $stream,
        private readonly ?int $size = null,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
    ) {
        if ($error < UPLOAD_ERR_OK || $error > UPLOAD_ERR_EXTENSION) {
            throw new \InvalidArgumentException('The upload error must be one of the UPLOAD_ERR_* constants.');
        }
    }

    public function getStream(): StreamInterface
    {
        $this->assertAvailable();
        return $this->stream;
    }

    public function moveTo(string $targetPath): void
    {
        $this->assertAvailable();
        if ($targetPath === '') {
            throw new \InvalidArgumentException('The target path must not be empty.');
        }
        $text = $this->stream instanceof Stream ? $this->stream->text() : null;
        $target = @fopen($targetPath, 'wb');
        if ($target === false) {
            throw new \RuntimeException("Cannot write the uploaded file to {$targetPath}.");
        }
        try {
            if ($text !== null) {
                // Content still held as a string needs no stream to copy it through.
                if (@fwrite($target, $text) !== strlen($text)) {
                    throw new \RuntimeException("Cannot write the uploaded file to {$targetPath}.");
                }
                $this->moved = true;
                return;
            }
            if ($this->stream->isSeekable()) {
                $this->stream->rewind();
            }
            while (!$this->stream->eof()) {
                $chunk = $this->stream->read(65536);
                if ($chunk !== '' && @fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException("Cannot write the uploaded file to {$targetPath}.");
                }
            }
        } finally {
            fclose($target);
        }
        $this->moved = true;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    private function assertAvailable(): void
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('The upload failed, so it has no content.');
        }
        if ($this->moved) {
            throw new \RuntimeException('The uploaded file has already been moved.');
        }
    }
}
