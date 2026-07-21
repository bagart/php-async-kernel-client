<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Drivers;

use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use Revolt\EventLoop;

final class AmpNetworkTickableDriver implements ASKTickableContract
{
    public function tick(int $systemPressure): void
    {
        $driver = EventLoop::getDriver();
        $driver->defer(fn () => $driver->stop());
        $driver->run();
    }

    public function pressure(): int
    {
        return 0;
    }

    public function queueSize(): int
    {
        return 0;
    }

    public function isIdle(): bool
    {
        return true;
    }
}
