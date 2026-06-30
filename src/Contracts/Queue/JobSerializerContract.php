<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Queue;

use BAGArt\AsyncKernel\Job\AsyncJob;

interface JobSerializerContract
{
    public function serialize(AsyncJob $job): string;

    public function deserialize(string $payload): AsyncJob;

    /**
     * Serialize a job into a compact meta payload for storage in the state hash.
     */
    public function serializeToMeta(AsyncJob $job): string;

    /**
     * Reconstruct a Job from the stored hash meta (including payload field).
     *
     * @param  array<string, string>  $meta
     */
    public function deserializeFromMeta(string $jobId, array $meta): ?AsyncJob;

    /**
     * Full job snapshot for zombie recovery rehydration.
     * Includes full job state, attempt, and fencing metadata.
     * MUST NOT reconstruct from partial meta — only from full payload.
     */
    public function serializeToRecoveryPayload(AsyncJob $job, string $fencingToken = ''): string;
}
