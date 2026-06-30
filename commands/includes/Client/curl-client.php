<?php

declare(strict_types=1);

use BAGArt\ASKClient\Client\CurlMultiClient;
use BAGArt\ASKClient\Contracts\Client\NetworkClientContract;

/**
 * Build a cURL-based NetworkClientContract.
 *
 * Usage:
 *   $makeClient = require __DIR__.'/../Client/curl-client.php';
 *   $client = $makeClient();
 *
 * @return callable(array<string, mixed>): NetworkClientContract
 */
return static function (array $options = []): NetworkClientContract {
    return new CurlMultiClient(
        multiHandle: $options['multi_handle'] ?? null,
    );
};
