<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKFuture;
use BAGArt\ASKClient\ASKTransport;
use BAGArt\ASKClient\Contracts\ASKFutureContract;
use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\ASKClient\Transporting\HttpTransports\GuzzleTransport;

/**
 * Build a promise-based guzzle transport.
 *
 * Uses GuzzleTransport internally and returns ASKFuture that resolves when
 * the underlying promise settles.
 *
 * @return callable(array<string, mixed>): ASKTransport
 */
return static function (array $options = []): ASKTransport {
    $guzzleTransport = new GuzzleTransport();

    return ASKTransport::wrap(
        static function (ASKHttpRequest $operation) use ($guzzleTransport): ASKFutureContract {
            $promise = $guzzleTransport->requestAsync($operation);

            return ASKFuture::pending(function () use ($promise): mixed {
                return $promise->wait();
            });
        },
    );
};
