<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Client;

/**
 * A network client that can pre-open connections and keep them idle for reuse.
 *
 * Implementations are free to treat this as a no-op (e.g. when keep-alive is
 * disabled); {@see warmUp()} then returns 0.
 */
interface WarmableClientContract
{
    /**
     * Open up to {@see $count} warm connections to {@see $host} and park them idle.
     *
     * @param  positive-int  $count  Desired number of warm connections.
     * @param  int<1, 65535> $port   Target TCP port.
     *
     * @return int Number of connections actually warmed (and now idle).
     */
    public function warmUp(string $host, int $count, int $port = 443): int;
}
