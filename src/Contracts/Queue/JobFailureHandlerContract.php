<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Queue;

use BAGArt\AsyncKernel\Job\AsyncJob;
use Throwable;

interface JobFailureHandlerContract
{
    public function handle(AsyncJob $job, Throwable $e): void;
}
