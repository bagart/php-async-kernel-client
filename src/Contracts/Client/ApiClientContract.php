<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Client;

use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\ASKClient\Response\ASKHttpResponse;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;

interface ApiClientContract
{
    public function requestAsync(ASKHttpRequest $request): ASKPromiseContract;

    public function request(ASKHttpRequest $request): ASKHttpResponse;

    /** @return ASKTickableContract[] */
    public function tickable(): array;
}
