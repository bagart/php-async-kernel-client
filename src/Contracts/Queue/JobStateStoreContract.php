<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Queue;

interface JobStateStoreContract
{
    public function tryStart(string $jobId, string $workerId, int $ttlSeconds = 3600): bool;

    /**
     * Mark job as running — caller MUST ensure idempotency via dedup or external gate.
     * With fencing: stores workerId + monotonic token for verify-before-execute.
     */
    public function claim(string $jobId, string $workerId, ?string $payload = null): bool;

    /**
     * Claim with fencing token. Returns the token if claim succeeded, null otherwise.
     */
    public function claimWithFencing(string $jobId, string $workerId, ?string $payload = null): ?string;

    /**
     * Verify fencing token before executing. Returns true if token matches stored value.
     */
    public function verifyFencing(string $jobId, string $workerId, string $fencingToken): bool;

    /**
     * Retrieve the stored serialized payload for a job, if any.
     */
    public function getPayload(string $jobId): ?string;

    public function markCompleted(string $jobId): bool;

    public function markFailed(string $jobId, string $error): void;

    public function markRetry(string $jobId, int $retryAt): void;

    public function markDeadLetter(string $jobId, string $error): void;

    public function isCompleted(string $jobId): bool;

    public function getState(string $jobId): ?string;

    /**
     * @return array{state: ?string, attempt: int, workerId: ?string, fencingToken: ?string, startedAt: ?int, completedAt: ?int, retryAt: ?int, error: ?string}|null
     */
    public function getMeta(string $jobId): ?array;

    /**
     * @return list<string>
     */
    public function findZombies(int $timeoutSeconds): array;

    /**
     * Prevent duplicate retry dispatch per attempt (SET NX).
     */
    public function tryMarkRetryDispatch(string $jobId): bool;

    public function forget(string $jobId): void;

    /**
     * Record a worker heartbeat with TTL.
     */
    public function heartbeat(string $workerId, int $ttlSeconds): void;

    /**
     * Atomic consume pipeline: read state → check → claim → mark running → return token.
     * Eliminates race between read, check, and claim.
     */
    public function atomicConsume(string $jobId, string $workerId, ?string $payload = null): ?string;
}
