<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts;

use BAGArt\ASKClient\ASKNextHandler;

interface ASKHandlerContract
{
    public function __invoke(
        object $operation,
        ASKContextContract $context,
        ASKNextHandler $next,
    ): ASKFutureContract;
}
