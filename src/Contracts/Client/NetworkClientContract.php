<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Client;

use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Contracts\Daemons\WithASKTickableContract;

interface NetworkClientContract extends WithASKTickableContract
{
    public function request(ASKHttpRequest $request): ASKPromiseContract;
}
