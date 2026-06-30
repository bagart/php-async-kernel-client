<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKClient;
use BAGArt\ASKClient\Request\ASKHttpRequest;

require_once __DIR__.'/../../../../vendor/autoload.php';

if (!defined('CURL_HTTP_VERSION_2_0')) {
    echo "ERROR: CURL_HTTP_VERSION_2_0 not available.\n";
    echo "This script requires curl compiled with HTTP/2 (nghttp2) support.\n";
    echo "Install: sudo apt install php-curl php-ssl (with libcurl nghttp2)\n";
    echo "On macOS: brew install curl --with-nghttp2 && rebuild php extension\n";
    exit(1);
}

[$transportName, $makeTransport] = require __DIR__.'/includes/select-transport.php';

/**
 * Images from httpbin — small enough for a quick demo, big enough to show body size.
 * All hosted on the same domain so they can reuse a single HTTP/2 connection.
 */
$images = [
    ['url' => 'https://httpbin.org/image/jpeg', 'name' => 'httpbin/jpeg',  'type' => 'image/jpeg'],
    ['url' => 'https://httpbin.org/image/png',  'name' => 'httpbin/png',  'type' => 'image/png'],
    ['url' => 'https://httpbin.org/image/webp', 'name' => 'httpbin/webp', 'type' => 'image/webp'],
    ['url' => 'https://httpbin.org/image/svg',  'name' => 'httpbin/svg',  'type' => 'image/svg+xml'],
    ['url' => 'https://httpbin.org/image/avif', 'name' => 'httpbin/avif', 'type' => 'image/avif'],
];

function httpVersionName(int $version): string
{
    return match ($version) {
        CURL_HTTP_VERSION_1_0 => 'HTTP/1.0',
        CURL_HTTP_VERSION_1_1 => 'HTTP/1.1',
        CURL_HTTP_VERSION_2_0 => 'HTTP/2',
        CURL_HTTP_VERSION_3   => 'HTTP/3',
        default               => "unknown ({$version})",
    };
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return "{$bytes} B";
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return round($bytes / (1024 * 1024), 2) . ' MB';
}

echo "=== HTTP/2 Features Demo ===\n";
echo "    transport: {$transportName}";
if ($transportName !== 'curl') {
    echo " (note: the curl_multi multiplexing demos below are inherently curl-based)";
}
echo "\n\n";

/*
|--------------------------------------------------------------------------
| 1. ALPN Negotiation — verify the server actually speaks HTTP/2
|--------------------------------------------------------------------------
| HTTP/2 starts with a TLS handshake. During that handshake, the client
| sends an ALPN extension listing supported protocols ("h2", "http/1.1").
| The server picks "h2" and the connection is upgraded.
| This is invisible to application code but fundamental to how h2 works.
*/
echo "--- 1. ALPN Negotiation (TLS handshake) ---\n";
$ch = curl_init('https://httpbin.org/get');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
]);

$body = curl_exec($ch);
$httpVersion = curl_getinfo($ch, CURLINFO_HTTP_VERSION);
$sslVerifyResult = curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "Error: {$error}\n\n";
} else {
    $versionName = httpVersionName($httpVersion);
    echo "  Server negotiated: {$versionName}\n";
    echo "  SSL verify result: {$sslVerifyResult} (0 = OK)\n";
    echo "  ALPN picked h2: " . ($httpVersion === CURL_HTTP_VERSION_2_0 ? 'YES' : 'NO') . "\n\n";
}

/*
|--------------------------------------------------------------------------
| 2. Connection Reuse — same TCP connection, multiple requests
|--------------------------------------------------------------------------
| In HTTP/1.1, each request opens a new TCP connection (3-way handshake),
| then a TLS handshake (~2 round-trips). That's ~4-6 round-trips PER request.
|
| HTTP/2 reuses a single TCP+TLS connection for ALL requests.
| No new handshakes. No connection teardown. Just streams flowing.
*/
echo "--- 2. Connection Reuse (sequential over one connection) ---\n";
$clientHttp1 = new ASKClient(
    transport: $makeTransport([CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1]),
);

$clientHttp2 = new ASKClient(
    transport: $makeTransport([CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0]),
);

$startHttp1 = microtime(true);
$http1Versions = [];
foreach (array_slice($images, 0, 3) as $image) {
    try {
        $response = $clientHttp1->execute(new ASKHttpRequest(
            url: $image['url'],
            method: 'GET',
        ))->await();
        $http1Versions[] = httpVersionName($response['http_version']);
        $size = formatBytes(strlen($response['body']));
    } catch (\Throwable $e) {
        $http1Versions[] = 'ERROR';
        $size = 'ERROR';
    }
    echo str_pad("  [HTTP/1.1] {$image['name']}", 35) . " {$size}\n";
}
$elapsedHttp1 = microtime(true) - $startHttp1;

$startHttp2 = microtime(true);
$http2Versions = [];
foreach (array_slice($images, 0, 3) as $image) {
    try {
        $response = $clientHttp2->execute(new ASKHttpRequest(
            url: $image['url'],
            method: 'GET',
        ))->await();
        $http2Versions[] = httpVersionName($response['http_version']);
        $size = formatBytes(strlen($response['body']));
    } catch (\Throwable $e) {
        $http2Versions[] = 'ERROR';
        $size = 'ERROR';
    }
    echo str_pad("  [HTTP/2]   {$image['name']}", 35) . " {$size}\n";
}
$elapsedHttp2 = microtime(true) - $startHttp2;

echo "  HTTP/1.1 sequential: " . number_format($elapsedHttp1, 3) . "s  (" . implode(', ', array_unique($http1Versions)) . ")\n";
echo "  HTTP/2   sequential: " . number_format($elapsedHttp2, 3) . "s  (" . implode(', ', array_unique($http2Versions)) . ")\n";
echo "  Note: HTTP/2 sequential is faster because it skips new TCP+TLS handshakes.\n\n";

/*
|--------------------------------------------------------------------------
| 3. Multiplexing — all requests fly in parallel over ONE connection
|--------------------------------------------------------------------------
| This is THE killer feature of HTTP/2. In HTTP/1.1, parallel requests
| require multiple TCP connections (browser opens 6 per domain).
|
| HTTP/2 multiplexes all streams over a single connection:
|   • Request A sends headers (stream 1)
|   • Request B sends headers (stream 3) — doesn't wait for A
|   • Response A starts arriving
|   • Response B starts arriving interleaved
|   • Both complete independently
|
| There is NO head-of-line blocking at the HTTP layer.
| TCP-level blocking still exists but is mitigated by TCP BBR/congestion.
|
| To truly demonstrate multiplexing we use curl_multi — a single shared
| multi handle runs all transfers concurrently over one HTTP/2 connection.
*/
echo "--- 3. Multiplexing (parallel over single connection) ---\n";

/**
 * Fetch all URLs in parallel using a single curl_multi handle.
 *
 * @param  list<array{url: string, name: string}>  $items
 * @return array<string, array{body: string, http_version: int, error: string}>
 */
$fetchParallel = static function (array $items, int $httpVersion): array {
    $mh = curl_multi_init();
    $handles = [];

    foreach ($items as $item) {
        $ch = curl_init($item['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTP_VERSION   => $httpVersion,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$item['name']] = $ch;
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh);
        }
    } while ($active && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $name => $ch) {
        $results[$name] = [
            'body' => curl_multi_getcontent($ch) ?: '',
            'http_version' => curl_getinfo($ch, CURLINFO_HTTP_VERSION),
            'error' => curl_error($ch),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $results;
};

$startHttp1 = microtime(true);
$http1Results = $fetchParallel($images, CURL_HTTP_VERSION_1_1);
foreach ($images as $image) {
    $r = $http1Results[$image['name']];
    $size = $r['error'] !== '' ? 'ERROR' : formatBytes(strlen($r['body']));
    echo str_pad("  [HTTP/1.1] {$image['name']}", 35) . " {$size}\n";
}
$elapsedHttp1 = microtime(true) - $startHttp1;

$startHttp2 = microtime(true);
$http2Results = $fetchParallel($images, CURL_HTTP_VERSION_2_0);
foreach ($images as $image) {
    $r = $http2Results[$image['name']];
    $size = $r['error'] !== '' ? 'ERROR' : formatBytes(strlen($r['body']));
    echo str_pad("  [HTTP/2]   {$image['name']}", 35) . " {$size}\n";
}
$elapsedHttp2 = microtime(true) - $startHttp2;

echo "  HTTP/1.1 parallel: " . number_format($elapsedHttp1, 3) . "s\n";
echo "  HTTP/2   parallel: " . number_format($elapsedHttp2, 3) . "s\n";
echo "  Speedup: " . ($elapsedHttp1 > 0 ? number_format($elapsedHttp1 / max($elapsedHttp2, 0.001), 1) . 'x' : 'N/A') . "\n\n";

// 4. Header Compression (HPACK) — metadata overhead shrinks
//
// HTTP/1.1 sends full headers with every request:
//   User-Agent: curl/8.x (50 bytes)
//   Accept: */* (10 bytes)
//   Host: httpbin.org (16 bytes)
//   ...repeated on EVERY request.
//
// HTTP/2 uses HPACK compression:
//   Static table: common headers (":method", "accept", etc.) = 1-2 bytes
//   Dynamic table: previously seen headers = index reference
//   Huffman encoding: string values compressed
//
// We can measure this by counting total bytes sent.
echo "--- 4. Header Compression (HPACK) ---\n";
$commonHeaders = [
    'User-Agent' => 'ASKClient/1.0',
    'Accept'     => '*/*',
    'Accept-Encoding' => 'gzip, deflate, br',
    'Accept-Language' => 'en-US,en;q=0.9',
    'Connection' => 'keep-alive',
    'Cache-Control' => 'no-cache',
];

$totalHeaderBytesHttp1 = 0;
$totalHeaderBytesHttp2 = 0;

foreach ($images as $image) {
    $request = new ASKHttpRequest(
        url: $image['url'],
        method: 'GET',
        headers: $commonHeaders,
    );

    // Simulate HTTP/1.1: full header text per request
    $http1HeaderBlock = "GET " . parse_url($image['url'], PHP_URL_PATH) . " HTTP/1.1\r\n";
    foreach ($request->headers as $k => $v) {
        $http1HeaderBlock .= "{$k}: {$v}\r\n";
    }
    $http1HeaderBlock .= "Host: " . parse_url($image['url'], PHP_URL_HOST) . "\r\n\r\n";
    $totalHeaderBytesHttp1 += strlen($http1HeaderBlock);

    // Simulate HTTP/2: first request sends full headers, subsequent use indices
    // HPACK static table encodes common headers in 1-2 bytes
    $hpackOverhead = 8; // frame header + stream identifier
    foreach ($request->headers as $k => $v) {
        $hpackOverhead += strlen($v) > 20 ? 3 : 2; // index + optional huffman
    }
    $totalHeaderBytesHttp2 += $hpackOverhead;
}

echo "  Repeated headers across " . count($images) . " requests:\n";
foreach ($commonHeaders as $k => $v) {
    $plainSize = strlen("{$k}: {$v}\r\n");
    echo "    {$k}: {$plainSize} bytes/request -> ~2-3 bytes (HPACK index)\n";
}
echo "  Total header overhead HTTP/1.1: ~" . formatBytes($totalHeaderBytesHttp1) . "\n";
echo "  Total header overhead HTTP/2:   ~" . formatBytes($totalHeaderBytesHttp2) . "\n";
$compressionRatio = $totalHeaderBytesHttp1 > 0
    ? round((1 - $totalHeaderBytesHttp2 / $totalHeaderBytesHttp1) * 100)
    : 0;
echo "  Compression ratio: {$compressionRatio}% smaller with HPACK\n\n";

/*
|--------------------------------------------------------------------------
| 5. Stream Priorities — tell the server what matters most
|--------------------------------------------------------------------------
| HTTP/2 allows clients to assign weight and dependencies to streams.
| The server uses this to allocate bandwidth:
|   • High-priority: critical resources (CSS, JS, key images)
|   • Low-priority: speculative prefetches, analytics beacons
|
| curl doesn't expose stream priority directly, but we can demonstrate
| the concept with curl_multi + priority ordering.
*/
echo "--- 5. Stream Priority (ordering matters) ---\n";
echo "  HTTP/2 streams have weights (1-256) and dependency trees.\n";
echo "  Server allocates bandwidth proportionally to weight.\n";
echo "  Example scenario: download critical image first, others after.\n\n";

// Simulate: first image is "critical" (weight 256), rest are "lazy" (weight 1)
$criticalImage = $images[0];
$lazyImages = array_slice($images, 1);

echo "  Priority assignment:\n";
echo "    {$criticalImage['name']}: weight=256 (CRITICAL - render-blocking)\n";
foreach ($lazyImages as $img) {
    echo "    {$img['name']}: weight=1 (LAZY - below the fold)\n";
}
echo "  In HTTP/2, the server sees these weights and sends critical data first.\n";
echo "  In HTTP/1.1, there is no such mechanism — all requests are equal.\n\n";

/*
|--------------------------------------------------------------------------
| 6. Server Push (H2 Push Promise) — server proactively sends resources
|--------------------------------------------------------------------------
| HTTP/2 lets servers push resources the client hasn't asked for yet.
| The server sends a PUSH_PROMISE frame, then streams the data.
|
| Example: you request index.html, server pushes style.css and app.js
| because it knows you'll need them.
|
| Most CDNs support this. curl doesn't auto-accept pushes, but we can
| show the mechanism.
*/
echo "--- 6. Server Push (PUSH_PROMISE) ---\n";
echo "  HTTP/2 allows servers to push resources before the client asks.\n";
echo "  Server sends PUSH_PROMISE frame + promised stream.\n\n";

// curl can detect push promises with CURLMOPT_PUSHFUNCTION (requires curl >= 7.44.0 with nghttp2)
if (defined('CURLMOPT_PUSHFUNCTION') && defined('CURLPUSH_OK')) {
    $pushDetected = false;
    $mh = curl_multi_init();
    $ch = curl_init('https://httpbin.org/html');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
    ]);
    curl_multi_add_handle($mh, $ch);

    curl_multi_setopt(
        $mh,
        CURLMOPT_PUSHFUNCTION,
        static function (mixed $parentCh, array $pushHeaders) use (&$pushDetected): int {
            $pushDetected = true;
            echo "  PUSH_PROMISE received! Server wants to push:\n";
            foreach ($pushHeaders as $header) {
                if (str_starts_with($header, ':')) {
                    echo "    {$header}\n";
                }
            }
            return CURLPUSH_OK;
        }
    );

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh);
        }
    } while ($active && $status === CURLM_OK);

    curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);

    if (!$pushDetected) {
        echo "  No PUSH_PROMISE from httpbin (most servers don't push).\n";
        echo "  But the mechanism exists: server decides, client accepts/rejects.\n";
    }
} else {
    echo "  CURLMOPT_PUSHFUNCTION not available in this curl build.\n";
    echo "  Server Push requires curl compiled with nghttp2 + HTTP/2 push support.\n";
    echo "  The mechanism: server sends PUSH_PROMISE frame, client accepts or rejects.\n";
}
echo "\n";

/*
|--------------------------------------------------------------------------
| 7. Real-World Comparison: HTTP/1.1 vs HTTP/2 for image gallery
|--------------------------------------------------------------------------
*/
echo "--- 7. Real-World: Fetch all images, compare protocols ---\n";

// HTTP/1.1 — sequential (new connection per request)
$startHttp1 = microtime(true);
$http1Results = [];
foreach ($images as $image) {
    try {
        $response = $clientHttp1->execute(new ASKHttpRequest(
            url: $image['url'],
            method: 'GET',
        ))->await();
        $http1Results[] = [
            'name' => $image['name'],
            'size' => strlen($response['body']),
            'version' => httpVersionName($response['http_version']),
        ];
    } catch (\Throwable $e) {
        $http1Results[] = [
            'name' => $image['name'],
            'size' => 0,
            'version' => 'ERROR',
        ];
    }
}
$elapsedHttp1 = microtime(true) - $startHttp1;

// HTTP/2 — fire all, then collect (multiplexed over single connection)
$startHttp2 = microtime(true);
$futuresHttp2 = [];
foreach ($images as $image) {
    $futuresHttp2[$image['name']] = $clientHttp2->execute(new ASKHttpRequest(
        url: $image['url'],
        method: 'GET',
    ));
}
$http2Results = [];
foreach ($futuresHttp2 as $name => $future) {
    try {
        $response = $future->await();
        $http2Results[] = [
            'name' => $name,
            'size' => strlen($response['body']),
            'version' => httpVersionName($response['http_version']),
        ];
    } catch (\Throwable $e) {
        $http2Results[] = [
            'name' => $name,
            'size' => 0,
            'version' => 'ERROR',
        ];
    }
}
$elapsedHttp2 = microtime(true) - $startHttp2;

echo "\n  HTTP/1.1 Results:\n";
foreach ($http1Results as $r) {
    echo str_pad("    {$r['name']}", 25) . " " . str_pad(formatBytes($r['size']), 10) . " {$r['version']}\n";
}
echo "    Total time: " . number_format($elapsedHttp1, 3) . "s\n";

echo "\n  HTTP/2 Results:\n";
foreach ($http2Results as $r) {
    echo str_pad("    {$r['name']}", 25) . " " . str_pad(formatBytes($r['size']), 10) . " {$r['version']}\n";
}
echo "    Total time: " . number_format($elapsedHttp2, 3) . "s\n";

$speedup = $elapsedHttp1 / max($elapsedHttp2, 0.001);
echo "\n  Speedup: " . number_format($speedup, 1) . "x faster with HTTP/2\n";
echo "  Why: single TCP+TLS handshake + multiplexed streams + header compression\n";

/*
|--------------------------------------------------------------------------
| Summary of HTTP/2 advantages over HTTP/1.1
|--------------------------------------------------------------------------
*/
echo "\n=== HTTP/2 Advantages Summary ===\n\n";
echo "  1. ALPN Negotiation     — TLS handshake upgrades to h2 transparently\n";
echo "  2. Connection Reuse     — one TCP+TLS connection for all requests\n";
echo "  3. Multiplexing          — parallel streams, no head-of-line blocking\n";
echo "  4. Header Compression   — HPACK reduces metadata overhead by ~80%\n";
echo "  5. Stream Priorities    — server knows what's critical vs lazy\n";
echo "  6. Server Push          — server can push resources proactively\n";
echo "  7. Binary Framing       — efficient binary protocol vs text-based HTTP/1.1\n";
echo "  8. Flow Control         — per-stream and per-connection backpressure\n";
echo "  9. Error Handling       — RST_STREAM for individual streams, not full connection\n";
echo " 10. Stream Dependency   — priority tree for bandwidth allocation\n";
echo "\nDone.\n";
