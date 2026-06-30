<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Queue;

use BAGArt\AsyncKernel\Job\AsyncJob;

interface JobExecutorContract
{
    public function execute(AsyncJob $job): void;
}
