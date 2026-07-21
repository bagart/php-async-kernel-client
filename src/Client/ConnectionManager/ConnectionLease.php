<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client\ConnectionManager;

use BAGArt\ASKClient\Client\HttpsSocketClient\PooledConnection;

/**
 * Temporary ownership token for a pooled connection.
 *
 * After acquire() the caller holds exclusive access to the underlying connection.
 * Calling release() returns it to the pool (or closes it) exactly once —
 * subsequent calls are no-ops.
 */
final class ConnectionLease
{
    private bool $released = false;

    public function __construct(
        private readonly PooledConnection $connection,
        private readonly \Closure $onRelease,
    ) {
    }

    /**
     * The underlying pooled connection. Valid until release() is called.
     */
    public function connection(): PooledConnection
    {
        return $this->connection;
    }

    /**
     * Return the connection to the pool or close it.
     *
     * Safe to call multiple times — only the first invocation has an effect.
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        ($this->onRelease)($this->connection);
    }

    public function isReleased(): bool
    {
        return $this->released;
    }
}
