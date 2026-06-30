<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Queue;

interface JobDeduplicatorContract
{
    /**
     * Atomically mark a jobId as processed (SET NX).
     * Returns true if this is the first time (not processed yet).
     */
    public function tryMark(string $jobId): bool;

    /**
     * Mark with compound key: jobId + partitionKey + content hash.
     * Returns true if not yet processed.
     */
    public function tryMarkCompound(string $jobId, string $partitionKey, string $payload): bool;

    /**
     * Permanently mark as processed (or long TTL for idempotency layer).
     * Used after successful completion — survives dedup TTL expiry.
     */
    public function markProcessedPermanent(string $jobId): void;

    /**
     * Check if permanently processed.
     */
    public function isPermanentlyProcessed(string $jobId): bool;

    /**
     * Check if a jobId was already processed.
     */
    public function isProcessed(string $jobId): bool;

    /**
     * Remove dedup mark (for retry recovery).
     */
    public function forget(string $jobId): void;
}
