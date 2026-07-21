<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client\ConnectionManager;

/**
 * Lightweight counter sink for pool, connection, and TLS metrics.
 *
 * Every counter is an atomic increment — no sampling, no overflow checks.
 * Designed for the P0 dashboard with zero external dependencies.
 */
final class MetricsCollector
{
    // ── Pool metrics ───────────────────────────────────────────────────

    private int $poolHit = 0;
    private int $poolMiss = 0;
    private int $poolWaitCount = 0;

    /** Sum of wait durations in seconds across all queued acquires. */
    private float $poolWaitDuration = 0.0;

    /** Total requests processed (reused + fresh). */
    private int $totalRequests = 0;

    /** Requests served on a reused (pool-acquired) connection. */
    private int $reusedRequests = 0;

    // ── Connection lifecycle ───────────────────────────────────────────

    private int $connectionsCreated = 0;
    private int $connectionsClosed = 0;

    /** Sum of per-connection request counts (for avg requests/conn). */
    private int $connectionRequestSum = 0;

    /** Number of connections included in $connectionRequestSum. */
    private int $connectionRequestCount = 0;

    /** Sum of connection lifetimes in seconds (for avg lifetime). */
    private float $connectionLifetimeSum = 0.0;

    /** Number of connections included in $connectionLifetimeSum. */
    private int $connectionLifetimeCount = 0;

    // ── TLS metrics ────────────────────────────────────────────────────

    private int $tlsHandshakeCount = 0;
    private float $tlsHandshakeDurationSum = 0.0;
    private int $tlsHandshakeFailed = 0;

    // ── Pool ───────────────────────────────────────────────────────────

    public function incrementPoolHit(): void
    {
        $this->poolHit++;
    }

    public function incrementPoolMiss(): void
    {
        $this->poolMiss++;
    }

    public function incrementPoolWaitCount(): void
    {
        $this->poolWaitCount++;
    }

    public function addPoolWaitDuration(float $seconds): void
    {
        $this->poolWaitDuration += $seconds;
    }

    public function incrementTotalRequests(bool $reused): void
    {
        $this->totalRequests++;
        if ($reused) {
            $this->reusedRequests++;
        }
    }

    // ── Connection ─────────────────────────────────────────────────────

    public function incrementConnectionsCreated(): void
    {
        $this->connectionsCreated++;
    }

    public function recordConnectionClosed(int $requestCount, float $lifetimeSeconds): void
    {
        $this->connectionsClosed++;
        $this->connectionRequestSum += $requestCount;
        $this->connectionRequestCount++;
        $this->connectionLifetimeSum += $lifetimeSeconds;
        $this->connectionLifetimeCount++;
    }

    // ── TLS ────────────────────────────────────────────────────────────

    public function recordTlsHandshake(float $durationSeconds, bool $success): void
    {
        $this->tlsHandshakeCount++;
        $this->tlsHandshakeDurationSum += $durationSeconds;
        if (!$success) {
            $this->tlsHandshakeFailed++;
        }
    }

    // ── Snapshot ───────────────────────────────────────────────────────

    /**
     * @return array<string, float|int>
     */
    public function snapshot(): array
    {
        return [
            // Pool
            'pool.hit' => $this->poolHit,
            'pool.miss' => $this->poolMiss,
            'pool.hit_rate' => $this->computeRate($this->poolHit, $this->poolHit + $this->poolMiss),
            'pool.reuse_ratio' => $this->computeRate($this->reusedRequests, $this->totalRequests),
            'pool.wait_count' => $this->poolWaitCount,
            'pool.wait_duration_total' => $this->poolWaitDuration,
            // Connection
            'connections.created' => $this->connectionsCreated,
            'connections.closed' => $this->connectionsClosed,
            'connections.requests_avg' => $this->avg($this->connectionRequestSum, $this->connectionRequestCount),
            'connections.lifetime_avg' => $this->avg($this->connectionLifetimeSum, $this->connectionLifetimeCount),
            // TLS
            'tls.handshake.count' => $this->tlsHandshakeCount,
            'tls.handshake.duration_avg_ms' => $this->avg($this->tlsHandshakeDurationSum * 1_000, $this->tlsHandshakeCount),
            'tls.handshake.failed' => $this->tlsHandshakeFailed,
        ];
    }

    public function reset(): void
    {
        $this->poolHit = 0;
        $this->poolMiss = 0;
        $this->poolWaitCount = 0;
        $this->poolWaitDuration = 0.0;
        $this->totalRequests = 0;
        $this->reusedRequests = 0;
        $this->connectionsCreated = 0;
        $this->connectionsClosed = 0;
        $this->connectionRequestSum = 0;
        $this->connectionRequestCount = 0;
        $this->connectionLifetimeSum = 0.0;
        $this->connectionLifetimeCount = 0;
        $this->tlsHandshakeCount = 0;
        $this->tlsHandshakeDurationSum = 0.0;
        $this->tlsHandshakeFailed = 0;
    }

    private function computeRate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 4) : 0.0;
    }

    private function avg(float|int $sum, int $count): float
    {
        return $count > 0 ? round($sum / $count, 4) : 0.0;
    }
}
