<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Client;

use BAGArt\ASKClient\Contracts\ASKContextContract;
use BAGArt\ASKClient\Contracts\ASKFutureContract;

interface ASKClientContract
{
    public function execute(
        object $operation,
        ?ASKContextContract $context = null,
    ): ASKFutureContract;
}
