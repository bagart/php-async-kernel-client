<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Transporting;

use BAGArt\ASKClient\Contracts\ASKContextContract;
use BAGArt\ASKClient\Contracts\ASKFutureContract;

interface ASKTransportContract
{
    public function execute(
        object $operation,
        ASKContextContract $context,
    ): ASKFutureContract;
}
