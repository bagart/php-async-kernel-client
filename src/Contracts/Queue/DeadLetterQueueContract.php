<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Queue;

use BAGArt\AsyncKernel\Job\AsyncJob;
use Throwable;

interface DeadLetterQueueContract
{
    /**
     * Push a job that has exhausted all retry attempts into the dead letter queue.
     *
     * @param  array{attempts: list<int>, partitions: list<string>, retryChain: list<int>, workerIds: list<string>}  $history
     */
    public function push(AsyncJob $job, Throwable $error, array $history = []): void;
}
