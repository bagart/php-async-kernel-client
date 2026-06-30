<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Drivers;

use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise as P;

final class GuzzleNetworkTickableDriver implements ASKTickableContract
{
    public function __construct(
        private readonly ?CurlMultiHandler $curlMultiHandler = null,
        private int &$activeRequestsCount = 0,
    ) {
    }

    public function tick(int $systemPressure): void
    {
        $this->curlMultiHandler?->tick();
        P\Utils::queue()->run();
    }

    public function pressure(): int
    {
        return 0;
    }

    public function isIdle(): bool
    {
        return $this->activeRequestsCount === 0;
    }

    public function queueSize(): int
    {
        return $this->activeRequestsCount;
    }
}
