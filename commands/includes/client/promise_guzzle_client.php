<?php

declare(strict_types=1);

use BAGArt\ASKClient\Client\ClientPromiseAdapter;
use BAGArt\ASKClient\Client\GuzzleClient;
use BAGArt\ASKClient\Contracts\Client\NetworkClientContract;

/**
 * Build a Guzzle-based NetworkClientContract whose promises carry tickables,
 * allowing synchronous ->wait() calls.
 *
 * @return callable(array<string, mixed>): NetworkClientContract
 */
return static function (array $options = []): NetworkClientContract {
    return new ClientPromiseAdapter(new GuzzleClient(
        curlMultiHandler: $options['curl_multi_handler'] ?? null,
    ));
};
