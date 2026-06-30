<?php

declare(strict_types=1);

use BAGArt\ASKClient\Client\HttpsSocketClient\HttpsSocketClient;
use BAGArt\ASKClient\Client\HttpsSocketClient\HttpsSocketClientConfig;
use BAGArt\ASKClient\Contracts\Client\NetworkClientContract;

/**
 * Build a raw TCP/TLS socket-based NetworkClientContract (HTTP/1.1).
 *
 * Usage:
 *   $makeClient = require __DIR__.'/../Client/socket-client.php';
 *   $client = $makeClient();
 *
 * @return callable(array<string, mixed>): NetworkClientContract
 */
return static function (array $options = []): NetworkClientContract {
    return new HttpsSocketClient(
        config: new HttpsSocketClientConfig(
            keepAlive: (bool)($options['keep_alive'] ?? false),
            forceIPv4: (bool)($options['force_ipv4'] ?? true),
            dnsCache: (bool)($options['dns_cache'] ?? true),
            dnsCacheTtl: (float)($options['dns_cache_ttl'] ?? 60.0),
        ),
    );
};
