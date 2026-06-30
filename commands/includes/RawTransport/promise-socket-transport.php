<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKFuture;
use BAGArt\ASKClient\ASKTransport;
use BAGArt\ASKClient\Contracts\ASKFutureContract;
use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\ASKClient\Transporting\HttpTransports\ASKSocketTransport;

/**
 * Build a promise-based socket transport (HTTP/1.1).
 *
 * Uses ASKSocketTransport internally and returns ASKFuture that resolves when
 * the underlying promise settles.
 *
 * @return callable(array<string, mixed>): ASKTransport
 */
return static function (array $options = []): ASKTransport {
    $socketTransport = new ASKSocketTransport();

    return ASKTransport::wrap(
        static function (ASKHttpRequest $operation) use ($socketTransport): ASKFutureContract {
            $promise = $socketTransport->requestAsync($operation);

            return ASKFuture::pending(function () use ($promise): mixed {
                return $promise->wait();
            });
        },
    );
};
