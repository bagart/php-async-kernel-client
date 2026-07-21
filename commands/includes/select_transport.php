<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKFuture;
use BAGArt\ASKClient\ASKTransport;
use BAGArt\ASKClient\Contracts\ASKFutureContract;
use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\ASKClient\Transporting\HttpTransports\ASKSocketTransport;
use BAGArt\ASKClient\Transporting\HttpTransports\AmpHttpTransport;
use BAGArt\ASKClient\Transporting\HttpTransports\CurlMultiTransport;
use BAGArt\ASKClient\Transporting\TransportRegistry;

/**
 * Resolve the transport factory from the --transport CLI option.
 *
 * Returns a list:  [name: string, make: callable(array $options = []): ASKTransport]
 *
 * Mode   | Aliases                                  | Behaviour
 * -------|------------------------------------------|--------------------------------------
 * await  | curl, await-curl, curl-await              | raw transport, sync curl
 *        | guzzle, await-guzzle, guzzle-await        | (ASKTransport + ASKFuture)
 *        | socket-h1, await-socket-h1                |
 *        | curl-multi, amphp-http                    | via TransportRegistry (HttpTransportContract → ASKTransport)
 * -------|------------------------------------------|--------------------------------------
 * promise| promise-curl, curl-promise                | CurlMultiTransport / GuzzleTransport /
 *        | promise-guzzle, guzzle-promise            | ASKSocketTransport (ASKPromise ->
 *        | promise-socket-h1, socket-h1-promise      | ASKFuture)
 *        | ask-socket, promise-ask-socket            |
 *
 * Resolution order:
 *   1. the ASK_TRANSPORT env var (used by example-daemon.php, which parses
 *      argv itself and forwards the choice here);
 *   2. the --transport= CLI option.
 *
 * Usage:
 *   [$transportName, $makeTransport] = require __DIR__.'/includes/select_transport.php';
 *
 * @return list{string, callable(array<string, mixed>): \BAGArt\ASKClient\ASKTransport}
 */

$raw = getenv('ASK_TRANSPORT');
if ($raw === false || $raw === '') {
    $options = getopt('', ['transport::']);
    $raw = is_string($options['transport'] ?? null) ? $options['transport'] : '';
}

$type = strtolower(ltrim($raw, '-='));

// Build a generic ASKTransport wrapper from any HttpTransportContract
$wrapTransport = static function (string $registryType): callable {
    return static function (array $options = []) use ($registryType): ASKTransport {
        $transport = TransportRegistry::build()->make($registryType);

        return ASKTransport::wrap(
            static function (ASKHttpRequest $operation) use ($transport): ASKFutureContract {
                $promise = $transport->requestAsync($operation);

                return ASKFuture::pending(function () use ($promise): mixed {
                    return $promise->wait();
                });
            },
        );
    };
};

return match ($type) {
    'promise-curl', 'curl-promise' => ['curl-promise', require __DIR__.'/transport/promise_curl_transport.php'],
    'promise-guzzle', 'guzzle-promise' => ['guzzle-promise', require __DIR__.'/transport/promise_guzzle_transport.php'],
    'promise-socket-h1', 'socket-h1-promise', 'promise-ask-socket' => [
        ASKSocketTransport::TYPE,
        $wrapTransport(ASKSocketTransport::TYPE),
    ],
    'guzzle', 'await-guzzle', 'guzzle-await' => ['guzzle', require __DIR__.'/transport/guzzle_transport.php'],
    'ask-socket', 'await-socket-h1', 'socket-h1-await', 'socket-h1' => [
        ASKSocketTransport::TYPE,
        static function (array $options = []): ASKTransport {
            return ASKTransport::wrap(
                static function (ASKHttpRequest $operation) use ($options): ASKFutureContract {
                    $transport = TransportRegistry::build()->make(ASKSocketTransport::TYPE);
                    $promise = $transport->requestAsync($operation);

                    while ($promise->getState() === \BAGArt\AsyncKernel\Contracts\ASKPromiseContract::PENDING) {
                        $transport->tick(0);
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
                        $promise->getReason() ?? new \BAGArt\ASKClient\Exceptions\ASKNetworkException('Socket request failed'),
                    );
                },
            );
        },
    ],
    'amphp-http', 'amp' => [$type, $wrapTransport(AmpHttpTransport::TYPE)],
    'curl-multi' => [$type, $wrapTransport(CurlMultiTransport::TYPE)],
    'await-curl', 'curl-await', 'curl', '' => ['curl', require __DIR__.'/transport/curl_transport.php'],
    default => throw new \InvalidArgumentException(
        "Unknown transport: '{$raw}'. Supported: curl, await-curl, curl-await, promise-curl, curl-promise, "
        .'guzzle, await-guzzle, guzzle-await, promise-guzzle, guzzle-promise, '
        .'socket-h1, ask-socket, await-socket-h1, promise-socket-h1, socket-h1-promise, '
        .'promise-ask-socket, curl-multi, amphp-http, amp.'
    ),
};
