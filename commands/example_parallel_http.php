<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKClient;
use BAGArt\ASKClient\ASKContext;
use BAGArt\ASKClient\Request\ASKHttpRequest;

require_once __DIR__.'/../../../../vendor/autoload.php';

$sources = require __DIR__.'/includes/currency-sources.php';
[$transportName, $makeTransport] = require __DIR__.'/includes/select_transport.php';

$client = new ASKClient(
    transport: $makeTransport(),
);

echo "=== ASKClient Parallel HTTP Example ===\n";
echo "    transport: {$transportName}\n\n";

echo "--- Sequential requests ---\n";
$start = microtime(true);

foreach (array_slice($sources, 0, 3) as $source) {
    $future = $client->execute(new ASKHttpRequest(
        url: $source['url'],
        method: 'GET',
    ));

    $response = $future->await();
    $rate = $source['parseResponse']($response);
    echo str_pad("[{$source['name']}]", 30) ." USD/EUR: {$rate}\n";
}

$elapsed = microtime(true) - $start;
echo "Sequential time: " . number_format($elapsed, 3) . "s\n\n";

echo "--- Parallel requests (fire all, then await) ---\n";
$start = microtime(true);

$futures = [];
$indexed = array_values($sources);
foreach ($indexed as $i => $source) {
    $futures[$i] = $client->execute(new ASKHttpRequest(
        url: $source['url'],
        method: 'GET',
    ));
}

foreach ($futures as $i => $future) {
    try {
        $response = $future->await();
        $rate = $indexed[$i]['parseResponse']($response);
        echo str_pad("[{$indexed[$i]['name']}]", 30) ." USD/EUR: {$rate}\n";
    } catch (\Throwable $e) {
        echo str_pad("[{$indexed[$i]['name']}]", 30) ." ERROR: {$e->getMessage()}\n";
    }
}

$elapsed = microtime(true) - $start;
echo "Parallel time: " . number_format($elapsed, 3) . "s\n\n";

echo "--- Promise chaining (await) ---\n";
$start = microtime(true);

$source = $sources[0];
try {
    $response = $client->execute(new ASKHttpRequest(
        url: $source['url'],
        method: 'GET',
    ))->await();

    $rate = $source['parseResponse']($response);
    echo "Resolved: {$source['name']} => USD/EUR: {$rate}\n";
} catch (\Throwable $e) {
    echo "Rejected: {$e->getMessage()}\n";
}
echo "Finally: cleanup done\n";

$elapsed = microtime(true) - $start;
echo "Chaining time: " . number_format($elapsed, 3) . "s\n\n";

echo "--- Batch (fire all, then await) ---\n";
$start = microtime(true);

$futures = [];
foreach (array_slice($sources, 0, 4) as $i => $source) {
    $futures[] = $client->execute(
        new ASKHttpRequest(url: $source['url'], method: 'GET'),
        ASKContext::empty()->with('index', $i),
    );
}

foreach ($futures as $i => $future) {
    try {
        $response = $future->await();
        $source = $sources[$i];
        $rate = $source['parseResponse']($response);
        echo str_pad("[batch {$i}]", 20) ." {$source['name']}: {$rate}\n";
    } catch (\Throwable $e) {
        echo str_pad("[batch {$i}]", 20) ." ERROR: {$e->getMessage()}\n";
    }
}

$elapsed = microtime(true) - $start;
echo "Batch time: " . number_format($elapsed, 3) . "s\n";

echo "\nDone.\n";
