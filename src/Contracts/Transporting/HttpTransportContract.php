<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Transporting;

use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\ASKClient\Response\ASKHttpResponse;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;

interface HttpTransportContract
{
    public const string TYPE = 'undefined';

    public function request(ASKHttpRequest $request): ASKHttpResponse;

    public function requestAsync(ASKHttpRequest $request): ASKPromiseContract;
}
