<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKFuture;
use BAGArt\ASKClient\ASKTransport;
use BAGArt\ASKClient\Contracts\ASKFutureContract;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\ASKClient\Request\ASKHttpRequest;

/**
 * Build a cURL-based ASKClient transport.
 *
 * Usage:
 *   $makeTransport = require __DIR__.'/includes/curl-transport.php';
 *   $transport = $makeTransport();
 *   $transport = $makeTransport([CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0]);
 *
 * @param  array<int|string, mixed>  $curlOptions  extra/override CURLOPT_* => value pairs
 * @return callable(array<int|string, mixed>): ASKTransport
 */
return static function (array $curlOptions = []): ASKTransport {
    return ASKTransport::wrap(
        static function (ASKHttpRequest $operation) use ($curlOptions): ASKFutureContract {
            $ch = curl_init($operation->url);
            curl_setopt_array($ch, $curlOptions + [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_USERAGENT => 'ASKClient/1.0 (php-async-kernel-lib-client example)',
                CURLOPT_HTTPHEADER => $operation->formattedHeaders(),
            ]);

            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $httpVersion = curl_getinfo($ch, CURLINFO_HTTP_VERSION);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return ASKFuture::failed(new ASKNetworkException($error));
            }

            return ASKFuture::resolved([
                'status' => $status,
                'body' => $body,
                'http_version' => $httpVersion,
            ]);
        },
    );
};
