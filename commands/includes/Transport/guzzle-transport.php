<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKFuture;
use BAGArt\ASKClient\ASKTransport;
use BAGArt\ASKClient\Contracts\ASKFutureContract;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\ASKClient\Request\ASKHttpRequest;

/**
 * Build a Guzzle-based ASKClient transport.
 *
 * Mirror of curl-transport.php: same callable shape and the same response
 * array (['status', 'body', 'http_version']) so currency-sources.php parsers
 * work unchanged regardless of the chosen transport.
 *
 * Usage:
 *   $makeTransport = require __DIR__.'/includes/guzzle-transport.php';
 *   $transport = $makeTransport();
 *   $transport = $makeTransport(['timeout' => 20, 'http_errors' => false]);
 *
 * @param  array<string, mixed>  $clientOptions  extra/override Guzzle Client config keys
 * @return callable(array<string, mixed>): ASKTransport
 */
return static function (array $clientOptions = []): ASKTransport {
    $client = new GuzzleHttp\Client($clientOptions + [
        'timeout' => 15,
        'connect_timeout' => 5,
        'http_errors' => false,
        'allow_redirects' => true,
        'headers' => [
            'User-Agent' => 'ASKClient/1.0 (php-async-kernel-lib-client example; Guzzle)',
        ],
    ]);

    return ASKTransport::wrap(
        static function (ASKHttpRequest $operation) use ($client): ASKFutureContract {
            try {
                $response = $client->request($operation->method, $operation->url, [
                    'headers' => $operation->headers,
                    'body' => $operation->body,
                ]);

                return ASKFuture::resolved([
                    'status' => $response->getStatusCode(),
                    'body' => (string) $response->getBody(),
                    'http_version' => (float) $response->getProtocolVersion(),
                ]);
            } catch (\Throwable $e) {
                return ASKFuture::failed(new ASKNetworkException($e->getMessage(), 0, $e));
            }
        },
    );
};
