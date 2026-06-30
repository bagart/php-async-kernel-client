<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Queue;

use BAGArt\AsyncKernel\Job\AsyncJob;

interface PartitionStreamContract
{
    public function push(string $partitionKey, AsyncJob $job): string;

    /**
     * @return array<string, AsyncJob>
     */
    public function read(string $partitionKey, string $lastId, int $count = 1): array;

    /**
     * Acknowledge (delete) a stream entry.
     * In ownership mode, only ack if owned by the given workerId.
     */
    public function ack(string $partitionKey, string $entryId, ?string $workerId = null): void;

    /**
     * Claim ownership of an entry for a worker (fencing-aware).
     * Returns true if ownership was established.
     */
    public function claimOwnership(string $partitionKey, string $entryId, string $workerId, string $fencingToken): bool;

    /**
     * Check if an entry is owned by a specific worker with matching token.
     */
    public function verifyOwnership(
        string $partitionKey,
        string $entryId,
        string $workerId,
        string $fencingToken
    ): bool;

    public function trim(string $partitionKey, int $maxLen): void;

    public function length(string $partitionKey): int;
}
