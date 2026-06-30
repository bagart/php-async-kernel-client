<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Queue;

final class JsonCodec
{
    public function encode(mixed $dto): string
    {
        return serialize($dto);
    }

    public function decode(string $payload): mixed
    {
        return unserialize($payload, ['allowed_classes' => true]);
    }
}
