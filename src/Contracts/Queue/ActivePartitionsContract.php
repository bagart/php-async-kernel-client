<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Queue;

interface ActivePartitionsContract
{
    public function markActive(string $partitionKey, int $availableAt): void;

    public function claimNext(): ?string;

    /**
     * Claim up to $count partitions at once (for worker pools).
     *
     * @return list<string>
     */
    public function claimBatch(int $count): array;

    /**
     * Claim with adaptive delay based on system pressure.
     * Returns claimed partition key and computed delay.
     *
     * @return array{partition: ?string, delay: int}
     */
    public function claimNextAdaptive(int $streamPressure, int $retryPressure, int $zombieRate): array;

    public function requeue(string $partitionKey, int $delaySeconds): void;

    public function remove(string $partitionKey): void;

    public function count(): int;

    public function isScheduled(string $partitionKey): bool;

    /**
     * Decay partition penalties to prevent unbounded growth.
     */
    public function decayPenalties(float $factor = 0.9): void;

    /**
     * Scan partition keys using Redis SCAN-based iteration.
     *
     * @return array{cursor: int, items: list<string>}
     */
    public function scan(int $cursor, int $count): array;
}
