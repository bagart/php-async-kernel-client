<?php

declare(strict_types=1);

use BAGArt\ASKClient\Client\ClientPromiseAdapter;
use BAGArt\ASKClient\Client\CurlMultiClient;
use BAGArt\ASKClient\Contracts\Client\NetworkClientContract;

/**
 * Build a cURL-based NetworkClientContract whose promises carry tickables,
 * allowing synchronous ->wait() calls.
 *
 * @return callable(array<string, mixed>): NetworkClientContract
 */
return static function (array $options = []): NetworkClientContract {
    return new ClientPromiseAdapter(new CurlMultiClient(
        multiHandle: $options['multi_handle'] ?? null,
    ));
};
