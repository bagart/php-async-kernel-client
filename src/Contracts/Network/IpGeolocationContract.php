<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Network;

use BAGArt\ASKClient\Network\IpGeolocation;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;

interface IpGeolocationContract
{
    public function resolveAsync(): ASKPromiseContract;

    public function resolve(): IpGeolocation;
}
