<?php

declare(strict_types=1);

/**
 * Resolve the NetworkClientContract factory from the --client CLI option.
 *
 * Returns a list:  [name: string, make: callable(array $options = []): NetworkClientContract]
 *
 * Mode   | Aliases                                  | Behaviour
 * -------|------------------------------------------|--------------------------------------
 * await  | curl, await-curl, curl-await              | bare NetworkClientContract, promises
 *        | guzzle, await-guzzle, guzzle-await        | resolved via tick() polling in caller
 *        | socket-h1, await-socket-h1                |
 * -------|------------------------------------------|--------------------------------------
 * promise| promise-curl, curl-promise                | ClientPromiseAdapter wrapping the
 *        | promise-guzzle, guzzle-promise            | NetworkClientContract — promises carry
 *        | promise-socket-h1, socket-h1-promise      | tickables for ->wait() usage
 *
 * Resolution order:
 *   1. the ASK_CLIENT env var;
 *   2. the --client= CLI option.
 *
 * Usage:
 *   [$clientName, $makeClient] = require __DIR__.'/includes/select_client.php';
 *
 * @return array{string, callable(array<string, mixed>): \BAGArt\ASKClient\Contracts\Client\NetworkClientContract}
 */

$raw = getenv('ASK_CLIENT');
if ($raw === false || $raw === '') {
    $options = getopt('', ['client::']);
    $raw = is_string($options['client'] ?? null) ? $options['client'] : '';
}

return match (strtolower(ltrim($raw, '-='))) {
    'promise-guzzle', 'guzzle-promise' => ['guzzle-promise', require __DIR__.'/client/promise_guzzle_client.php'],
    'promise-socket-h1', 'socket-h1-promise' => ['socket-h1-promise', require __DIR__.'/client/promise_socket_client.php'],
    'promise-curl', 'curl-promise' => ['curl-promise', require __DIR__.'/client/promise_curl_client.php'],
    'await-guzzle', 'guzzle-await', 'guzzle' => ['guzzle', require __DIR__.'/client/guzzle_client.php'],
    'await-socket-h1', 'socket-h1' => ['socket-h1', require __DIR__.'/client/socket_client.php'],
    'await-curl', 'curl-await', 'curl', '' => ['curl', require __DIR__.'/client/curl_client.php'],
    default => throw new \InvalidArgumentException(
        "Unknown client: '{$raw}'. Supported: curl, await-curl, curl-await, promise-curl, curl-promise, "
        .'guzzle, await-guzzle, guzzle-await, promise-guzzle, guzzle-promise, '
        .'socket-h1, await-socket-h1, promise-socket-h1, socket-h1-promise.'
    ),
};
