<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client\Services;

use BAGArt\ASKClient\Contracts\Client\WarmableClientContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;

/**
 * Self-contained periodic pool warmer.
 *
 * Depends only on the abstract {@see WarmableClientContract} — no coupling to
 * a specific transport. An external coordinator decides when and how to tick
 * this warmer (daemon loop, timer, Kubernetes startup probe, …).
 */
final class PoolWarmer implements ASKTickableContract
{
    private float $lastWarmedAt = 0.0;

    public function __construct(
        private readonly WarmableClientContract $client,
        private readonly string $warmHost,
        private readonly int $warmCount,
        private readonly float $warmInterval,
        private readonly int $port = 443,
    ) {
    }

    public function tick(int $systemPressure): void
    {
        $now = microtime(true);

        if ($now - $this->lastWarmedAt >= $this->warmInterval) {
            $this->client->warmUp($this->warmHost, $this->warmCount, $this->port);
            $this->lastWarmedAt = $now;
        }
    }

    public function pressure(): int
    {
        return 0;
    }

    public function isIdle(): bool
    {
        return true;
    }

    public function queueSize(): int
    {
        return 0;
    }
}
