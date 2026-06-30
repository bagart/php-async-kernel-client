<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKClient;
use BAGArt\ASKClient\ASKContext;
use BAGArt\ASKClient\Contracts\ASKFutureContract;
use BAGArt\ASKClient\Request\ASKHttpRequest;

require_once __DIR__.'/../../../../vendor/autoload.php';

$sources = require __DIR__.'/includes/currency-sources.php';
[$transportName, $makeTransport] = require __DIR__.'/includes/select-transport.php';

$client = new ASKClient(
    transport: $makeTransport(),
);

$formatOk = require __DIR__.'/includes/format-ok.php';
$formatErr = require __DIR__.'/includes/format-err.php';

/**
 * Await one future, run its parser, and print a uniform success/error line.
 *
 * @param  array{url: string, name: string, parseResponse: callable}  $source
 */
$process = static function (array $source, ASKFutureContract $future) use ($formatOk, $formatErr): bool {
    $response = null;
    try {
        $response = $future->await();
        $rate = $source['parseResponse']($response);
        echo $formatOk($source, $rate)."\n";

        return true;
    } catch (Throwable $e) {
        echo $formatErr($source, $e, $response)."\n";

        return false;
    }
};

echo "=== ASKClient Batch Processing Example ===\n";
echo "    transport: {$transportName}\n\n";

echo "--- Batch 1: Fan-out (all sources in parallel) ---\n";
$start = microtime(true);

$futures = [];
foreach ($sources as $i => $source) {
    $futures[$i] = $client->execute(
        new ASKHttpRequest(url: $source['url'], method: 'GET'),
        ASKContext::empty()->with('batch', 'fan-out')->with('index', $i),
    );
}

$successful = 0;
$failed = 0;
foreach ($futures as $i => $future) {
    if ($process($sources[$i], $future)) {
        $successful++;
    } else {
        $failed++;
    }
}

$elapsed = microtime(true) - $start;
echo "Fan-out result: {$successful} ok, {$failed} failed in ".number_format($elapsed, 3)."s\n\n";

echo "--- Batch 2: Chunked processing (3 at a time) ---\n";
$start = microtime(true);

$chunkSize = 3;
$chunks = array_chunk($sources, $chunkSize, true);
$batchNum = 0;

foreach ($chunks as $chunk) {
    $batchNum++;
    echo "  Chunk #{$batchNum}:\n";

    $futures = [];
    foreach ($chunk as $i => $source) {
        $futures[$i] = $client->execute(
            new ASKHttpRequest(url: $source['url'], method: 'GET'),
            ASKContext::empty()->with('batch', "chunk-{$batchNum}")->with('index', $i),
        );
    }

    foreach ($futures as $i => $future) {
        $process($sources[$i], $future);
    }
}

$elapsed = microtime(true) - $start;
echo "Chunked result: ".number_format($elapsed, 3)."s\n\n";

echo "--- Batch 3: Pipeline (first -> transform -> second) ---\n";
$start = microtime(true);

foreach ([0, 1] as $step => $i) {
    $source = $sources[$i];
    $response = null;
    $future = $client->execute(
        new ASKHttpRequest(url: $source['url'], method: 'GET'),
        ASKContext::empty()->with('batch', 'pipeline')->with('step', $step),
    );
    try {
        $response = $future->await();
        $rate = $source['parseResponse']($response);
        echo '  Step '.($step + 1).': '.$formatOk($source, $rate)."\n";
    } catch (Throwable $e) {
        echo '  Step '.($step + 1).': '.$formatErr($source, $e, $response)."\n";
    }
}

$elapsed = microtime(true) - $start;
echo "Pipeline result: ".number_format($elapsed, 3)."s\n\n";

echo "--- Batch 4: Retry on failure (3 attempts) ---\n";
$start = microtime(true);

$attempts = 3;
$slice = array_slice($sources, 0, 3, true);

for ($attempt = 1; $attempt <= $attempts; $attempt++) {
    echo "  Attempt {$attempt}/{$attempts}:\n";
    $anySuccess = false;

    foreach ($slice as $source) {
        $future = $client->execute(
            new ASKHttpRequest(url: $source['url'], method: 'GET'),
            ASKContext::empty()->with('batch', 'retry')->with('attempt', $attempt),
        );

        if ($process($source, $future)) {
            $anySuccess = true;
        }
    }

    if ($anySuccess) {
        break;
    }

    if ($attempt < $attempts) {
        usleep(100_000);
    }
}

$elapsed = microtime(true) - $start;
echo "Retry result: ".number_format($elapsed, 3)."s\n";

echo "\nDone.\n";
