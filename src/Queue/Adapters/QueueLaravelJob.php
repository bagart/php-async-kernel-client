<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Queue\Adapters;

final class QueueLaravelJob
{
    public function __construct(
        public string $payload,
    ) {
    }
}
