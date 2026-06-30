<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client\HttpsSocketClient;

/**
 * In-process store of idle kept-open connections, keyed by "host:port".
 *
 * The pool caps the number of idle connections both per host and globally and
 * evicts entries that have been idle longer than the configured timeout. It owns
 * the lifecycle of idle sockets: when a connection is dropped (over capacity or
 * timed out) the underlying socket resource is closed.
 *
 * Active (in-flight) connections are NOT held here — only idle ones ready for reuse.
 */
final class ConnectionPool
{
    /** @var PooledConnection */
    private array $idle = [];

    /** Total idle connections across all keys. */
    private int $totalCount = 0;

    public function __construct(
        private readonly int $maxIdlePerHost,
        private readonly int $maxIdleTotal,
        private readonly float $idleTimeout,
    ) {
    }

    /**
     * Remove and return an idle connection for the given key, or null when none is available.
     * The returned connection is considered in-flight and is no longer tracked by the pool.
     */
    public function tryAcquire(string $key): ?PooledConnection
    {
        if (!isset($this->idle[$key]) || $this->idle[$key] === []) {
            unset($this->idle[$key]);
            return null;
        }

        // LIFO reuse
        while ($this->idle[$key] !== []) {
            /** @var PooledConnection $conn */
            $conn = array_pop($this->idle[$key]);
            $this->totalCount--;

            if ($conn->socket !== null && is_resource($conn->socket)) {
                if ($this->idle[$key] === []) {
                    unset($this->idle[$key]);
                }

                $conn->resetForReuse();
                return $conn;
            }

            // Stale resource (closed elsewhere) — drop silently and keep draining.
        }

        unset($this->idle[$key]);

        return null;
    }

    /**
     * Return a connection to the pool for future reuse. When the capacity is exceeded
     * the connection is closed instead of pooled.
     */
    public function release(PooledConnection $conn): void
    {
        if ($conn->socket === null || !is_resource($conn->socket)) {
            return;
        }

        $key = $conn->key;
        $hostCount = isset($this->idle[$key]) ? count($this->idle[$key]) : 0;

        if (
            $this->totalCount >= $this->maxIdleTotal ||
            $hostCount >= $this->maxIdlePerHost
        ) {
            $this->close($conn);

            return;
        }

        $conn->resetForReuse();
        $conn->lastActivity = microtime(true);

        $this->idle[$key][] = $conn;
        $this->totalCount++;
    }

    /**
     * Close and drop idle connections whose last activity predates the timeout.
     * Returns the number of connections evicted.
     */
    public function evictIdle(): int
    {
        if ($this->idle === []) {
            return 0;
        }

        $now = microtime(true);
        $evicted = 0;

        foreach ($this->idle as $key => $list) {
            $kept = [];
            foreach ($list as $conn) {
                $expired =
                    ($now - $conn->lastActivity > $this->idleTimeout) ||
                    $conn->socket === null ||
                    !is_resource($conn->socket);

                if ($expired) {
                    $this->close($conn);
                    $this->totalCount--;
                    $evicted++;
                } else {
                    $kept[] = $conn;
                }
            }

            if ($kept === []) {
                unset($this->idle[$key]);
            } else {
                $this->idle[$key] = $kept;
            }
        }

        return $evicted;
    }

    /**
     * Close every pooled connection. Used on shutdown.
     */
    public function closeAll(): void
    {
        foreach ($this->idle as $list) {
            foreach ($list as $conn) {
                $this->close($conn);
            }
        }

        $this->idle = [];
        $this->totalCount = 0;
    }

    public function idleCount(): int
    {
        return $this->totalCount;
    }

    public function idleCountForHost(string $key): int
    {
        return isset($this->idle[$key]) ? count($this->idle[$key]) : 0;
    }

    private function close(PooledConnection $conn): void
    {
        if (is_resource($conn->socket)) {
            @fclose($conn->socket);
        }
        $conn->socket = null;
    }
}
