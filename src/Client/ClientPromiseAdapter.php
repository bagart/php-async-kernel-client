<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client;

use BAGArt\ASKClient\Contracts\Client\NetworkClientContract;
use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Promise\ASKPromise;

final class ClientPromiseAdapter implements NetworkClientContract
{
    public function __construct(
        private readonly NetworkClientContract $inner,
    ) {
    }

    public function request(ASKHttpRequest $request): ASKPromiseContract
    {
        $promise = $this->inner->request($request);

        $wrapped = new ASKPromise(...$this->inner->tickable());

        $promise->then(
            fn (mixed $value): mixed => $wrapped->resolve($value),
            fn (\Throwable $reason): ?\Throwable => $wrapped->reject($reason),
        );

        return $wrapped;
    }

    public function tickable(): array
    {
        return $this->inner->tickable();
    }
}
