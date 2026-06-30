<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Queue;

interface PendingAckRegistryContract
{
    public function add(
        string $partitionKey,
        string $jobId,
        string $entryId,
        string $workerId = '',
        string $fencingToken = ''
    ): void;

    public function remove(string $partitionKey, string $jobId): void;

    /**
     * @return array<string, string>
     */
    public function getPending(string $partitionKey): array;

    public function getPendingOwnership(string $partitionKey, string $jobId): ?array;

    /**
     * @return list<string>
     */
    public function getPartitions(): array;

    public function cleanupPartition(string $partitionKey): void;
}
