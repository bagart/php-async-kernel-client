<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKFuture;
use BAGArt\ASKClient\ASKTransport;
use BAGArt\ASKClient\Client\HttpsSocketClient\HttpsSocketClient;
use BAGArt\ASKClient\Client\HttpsSocketClient\HttpsSocketClientConfig;
use BAGArt\ASKClient\Contracts\ASKFutureContract;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\ASKClient\Request\ASKHttpRequest;

/**
 * Build a raw socket-based ASKClient transport (HTTP/1.1, one-shot requests).
 *
 * Usage:
 *   $makeTransport = require __DIR__.'/../RawTransport/socket-transport.php';
 *   $transport = $makeTransport();
 *
 * Response array: ['status', 'body', 'http_version'] (compatible with
 * currency-sources.php parsers).
 *
 * @param  array<string, mixed>  $options  extra/override HttpsSocketClientConfig keys
 * @return callable(array<string, mixed>): ASKTransport
 */
return static function (array $options = []): ASKTransport {
    return ASKTransport::wrap(
        static function (ASKHttpRequest $operation) use ($options): ASKFutureContract {
            $client = new HttpsSocketClient(
                config: new HttpsSocketClientConfig(
                    keepAlive: (bool)($options['keep_alive'] ?? false),
                    forceIPv4: (bool)($options['force_ipv4'] ?? true),
                    dnsCache: (bool)($options['dns_cache'] ?? true),
                    dnsCacheTtl: (float)($options['dns_cache_ttl'] ?? 60.0),
                ),
            );

            $promise = $client->request($operation);

            while ($promise->getState() === \BAGArt\AsyncKernel\Contracts\ASKPromiseContract::PENDING) {
                $client->tick(0);
                usleep(1_000);
            }

            if ($promise->getState() === \BAGArt\AsyncKernel\Contracts\ASKPromiseContract::FULFILLED) {
                $response = $promise->getValue();

                return ASKFuture::resolved([
                    'status' => $response->getStatusCode(),
                    'body' => (string) $response->getBody(),
                    'http_version' => (float) $response->getProtocolVersion(),
                ]);
            }

            return ASKFuture::failed(
                $promise->getReason() ?? new ASKNetworkException('Socket request failed'),
            );
        },
    );
};
