<?php

declare(strict_types=1);

use BAGArt\ASKClient\Client\GuzzleClient;
use BAGArt\ASKClient\Contracts\Client\NetworkClientContract;

/**
 * Build a Guzzle-based NetworkClientContract.
 *
 * Usage:
 *   $makeClient = require __DIR__.'/../client/guzzle_client.php';
 *   $client = $makeClient();
 *
 * @return callable(array<string, mixed>): NetworkClientContract
 */
return static function (array $options = []): NetworkClientContract {
    return new GuzzleClient(
        curlMultiHandler: $options['curl_multi_handler'] ?? null,
    );
};
