<?php

declare(strict_types=1);

namespace Yiisoft\ResponseDownload;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

use const SEEK_CUR;
use const SEEK_END;
use const SEEK_SET;

/**
 * @internal
 */
final class ByteRangeStream implements StreamInterface
{
    private int $position = 0;
    private readonly int $size;

    public function __construct(
        private readonly StreamInterface $stream,
        private readonly int $start,
        int $end,
    ) {
        if ($start < 0 || $end < $start) {
            throw new RuntimeException('Invalid byte range.');
        }

        if (!$stream->isSeekable()) {
            throw new RuntimeException('Stream is not seekable.');
        }

        if (!$stream->isReadable()) {
            throw new RuntimeException('Stream is not readable.');
        }

        $this->size = $end - $start + 1;
        $this->stream->seek($start);
    }

    public function __toString(): string
    {
        try {
            $this->rewind();
            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        $this->stream->close();
    }

    public function detach()
    {
        return $this->stream->detach();
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= $this->size || $this->stream->eof();
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->size + $offset,
            default => throw new RuntimeException('Invalid seek mode.'),
        };

        if ($position < 0 || $position > $this->size) {
            throw new RuntimeException('Invalid seek offset.');
        }

        $this->stream->seek($this->start + $position);
        $this->position = $position;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Stream is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new RuntimeException('Length parameter cannot be negative.');
        }

        if ($length === 0 || $this->eof()) {
            return '';
        }

        $remaining = $this->size - $this->position;
        $contents = $this->stream->read(min($length, $remaining));
        $this->position += strlen($contents);

        return $contents;
    }

    public function getContents(): string
    {
        $contents = [];

        while (!$this->eof()) {
            $chunk = $this->read(8192);

            if ($chunk === '') {
                break;
            }

            $contents[] = $chunk;
        }

        return implode('', $contents);
    }

    public function getMetadata(?string $key = null)
    {
        return $this->stream->getMetadata($key);
    }
}
