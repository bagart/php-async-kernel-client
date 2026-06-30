<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKFuture;
use BAGArt\ASKClient\ASKTransport;
use BAGArt\ASKClient\Contracts\ASKFutureContract;
use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\ASKClient\Transporting\HttpTransports\CurlMultiTransport;

/**
 * Build a promise-based curl transport.
 *
 * Uses CurlMultiTransport internally and returns ASKFuture that resolves when
 * the underlying promise settles.
 *
 * @return callable(array<string, mixed>): ASKTransport
 */
return static function (array $options = []): ASKTransport {
    $curlTransport = new CurlMultiTransport();

    return ASKTransport::wrap(
        static function (ASKHttpRequest $operation) use ($curlTransport): ASKFutureContract {
            $promise = $curlTransport->requestAsync($operation);

            return ASKFuture::pending(function () use ($promise): mixed {
                return $promise->wait();
            });
        },
    );
};
