<?php

declare(strict_types=1);

/**
 * Resolve the transport factory from the --transport CLI option.
 *
 * Returns a list:  [name: string, make: callable(array $options = []): ASKTransport]
 *
 * Mode   | Aliases                                  | Behaviour
 * -------|------------------------------------------|--------------------------------------
 * await  | curl, await-curl, curl-await              | raw transport, sync curl/guzzle/socket
 *        | guzzle, await-guzzle, guzzle-await        | (ASKTransport + ASKFuture)
 *        | socket-h1, await-socket-h1                |
 * -------|------------------------------------------|--------------------------------------
 * promise| promise-curl, curl-promise                | CurlMultiTransport / GuzzleTransport /
 *        | promise-guzzle, guzzle-promise            | ASKSocketTransport (ASKPromise ->
 *        | promise-socket-h1, socket-h1-promise      | ASKFuture)
 *
 * Resolution order:
 *   1. the ASK_TRANSPORT env var (used by example-daemon.php, which parses
 *      argv itself and forwards the choice here);
 *   2. the --transport= CLI option.
 *
 * Usage:
 *   [$transportName, $makeTransport] = require __DIR__.'/includes/select-transport.php';
 *
 * @return list{string, callable(array<string, mixed>): \BAGArt\ASKClient\ASKTransport}
 */

$raw = getenv('ASK_TRANSPORT');
if ($raw === false || $raw === '') {
    $options = getopt('', ['transport::']);
    $raw = is_string($options['transport'] ?? null) ? $options['transport'] : '';
}

return match (strtolower(ltrim($raw, '-='))) {
    'promise-curl', 'curl-promise' => ['curl-promise', require __DIR__.'/Transport/promise-curl-transport.php'],
    'promise-guzzle', 'guzzle-promise' => ['guzzle-promise', require __DIR__.'/Transport/promise-guzzle-transport.php'],
    'promise-socket-h1', 'socket-h1-promise' => ['socket-h1-promise', require __DIR__.'/RawTransport/promise-socket-transport.php'],
    'await-guzzle', 'guzzle-await', 'guzzle' => ['guzzle', require __DIR__.'/Transport/guzzle-transport.php'],
    'await-socket-h1', 'socket-h1-await', 'socket-h1' => ['socket-h1', require __DIR__.'/RawTransport/socket-transport.php'],
    'await-curl', 'curl-await', 'curl', '' => ['curl', require __DIR__.'/Transport/curl-transport.php'],
    default => throw new \InvalidArgumentException(
        "Unknown transport: '{$raw}'. Supported: curl, await-curl, curl-await, promise-curl, curl-promise, "
        .'guzzle, await-guzzle, guzzle-await, promise-guzzle, guzzle-promise, '
        .'socket-h1, await-socket-h1, promise-socket-h1, socket-h1-promise.'
    ),
};
