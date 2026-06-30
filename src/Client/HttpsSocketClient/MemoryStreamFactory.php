<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client\HttpsSocketClient;

use Psr\Http\Message\StreamInterface;

final class MemoryStreamFactory
{
    public static function createFromString(string $content): StreamInterface
    {
        return new class ($content) implements StreamInterface {
            private int $pos = 0;

            private int $size;

            public function __construct(private readonly string $data)
            {
                $this->size = \strlen($this->data);
            }

            public function __toString(): string
            {
                return $this->data;
            }

            public function close(): void
            {
            }

            public function detach()
            {
                return null;
            }

            public function getSize(): int
            {
                return $this->size;
            }

            public function tell(): int
            {
                return $this->pos;
            }

            public function eof(): bool
            {
                return $this->pos >= $this->size;
            }

            public function isSeekable(): bool
            {
                return true;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                if ($whence === SEEK_SET) {
                    $this->pos = $offset;
                } elseif ($whence === SEEK_CUR) {
                    $this->pos += $offset;
                } elseif ($whence === SEEK_END) {
                    $this->pos = $this->size + $offset;
                }
            }

            public function rewind(): void
            {
                $this->pos = 0;
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                return 0;
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                $chunk = \substr($this->data, $this->pos, $length);
                $this->pos += \strlen($chunk);
                return $chunk;
            }

            public function getContents(): string
            {
                $chunk = \substr($this->data, $this->pos);
                $this->pos = $this->size;
                return $chunk;
            }

            public function getMetadata(?string $key = null)
            {
                return $key === null ? [] : null;
            }
        };
    }
}
