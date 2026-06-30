<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Drivers;

use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use GuzzleHttp\Promise\Utils;

final class GuzzleTickableDriver implements ASKTickableContract
{
    public function tick(int $systemPressure): void
    {
        Utils::queue()->run();
    }

    public function pressure(): int
    {
        return 0;
    }

    public function queueSize(): int
    {
        return Utils::queue()->isEmpty() ? 0 : 1;
    }

    public function isIdle(): bool
    {
        return Utils::queue()->isEmpty();
    }
}
