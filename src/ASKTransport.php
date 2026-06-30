<?php

declare(strict_types=1);

namespace BAGArt\ASKClient;

use BAGArt\ASKClient\Contracts\ASKContextContract;
use BAGArt\ASKClient\Contracts\ASKFutureContract;
use BAGArt\ASKClient\Contracts\Transporting\ASKTransportContract;

final class ASKTransport implements ASKTransportContract
{
    public function __construct(
        private readonly \Closure $executor,
    ) {
    }

    public static function wrap(callable $executor): self
    {
        return new self(\Closure::fromCallable($executor));
    }

    public function execute(object $operation, ASKContextContract $context): ASKFutureContract
    {
        return ($this->executor)($operation, $context);
    }
}
