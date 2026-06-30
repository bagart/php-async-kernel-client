<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Promise;

use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Exceptions\ASKException;
use BAGArt\AsyncKernel\Exceptions\ASKInterruptException;
use BAGArt\AsyncKernel\Promise\ASKPromise;
use GuzzleHttp\Promise\PromiseInterface;

final class GuzzlePromiseAdapter
{
    public static function wrap(
        PromiseInterface $guzzlePromise,
        ASKTickableContract ...$tickables,
    ): ASKPromiseContract {
        $promise = new ASKPromise(...$tickables);

        $guzzlePromise->then(
            function ($value) use ($promise) {
                $promise->resolve($value);
            },
            function ($reason) use ($promise) {
                if ($reason instanceof ASKInterruptException) {
                    throw $reason;
                }
                if ($reason instanceof \Throwable) {
                    $promise->reject($reason);
                } else {
                    $promise->reject(
                        new ASKException("[GuzzlePromiseAdapter] $reason")
                    );
                }
            }
        );

        return $promise;
    }
}
