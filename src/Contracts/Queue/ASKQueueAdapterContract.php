<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Queue;

interface ASKQueueAdapterContract
{
    public function push(string $queueName, string $payload): void;

    public function pushDelayed(string $queueName, string $payload, int $availableAt, ?string $partitionKey = null): void;

    public function pop(string $queueName): ?string;

    public function popDue(string $queueName, ?string $partitionKey = null): ?string;

    public function acquirePartition(string $partitionKey, int $ttl): bool;

    /**
     * @return array<int, string>
     */
    public function getPartitions(string $queueName, int $limit = 10, bool $random = true): array;

    public function size(string $queueName): int;
}
