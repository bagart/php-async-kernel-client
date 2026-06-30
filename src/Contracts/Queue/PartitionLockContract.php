<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Queue;

interface PartitionLockContract
{
    public function acquire(string $partitionKey, string $workerId, int $ttlSeconds): bool;

    public function renew(string $partitionKey, string $workerId, int $ttlSeconds): bool;

    public function release(string $partitionKey, string $workerId): void;

    public function isOwnedBy(string $partitionKey, string $workerId): bool;

    /**
     * Attempt to take over an expired lease.
     * Only succeeds if the current lease has expired (TTL passed and key still exists).
     */
    public function takeover(string $partitionKey, string $workerId, int $ttlSeconds): bool;

    /**
     * Get the current lock owner, or null if not locked.
     */
    public function getOwner(string $partitionKey): ?string;

    /**
     * Check if the partition lock has expired or does not exist.
     */
    public function isExpired(string $partitionKey): bool;
}
