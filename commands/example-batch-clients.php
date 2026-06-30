<?php

declare(strict_types=1);

use BAGArt\ASKClient\Contracts\Client\NetworkClientContract;
use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use Psr\Http\Message\ResponseInterface;

require_once __DIR__.'/../../../../vendor/autoload.php';

$sources = require __DIR__.'/includes/currency-sources.php';
[$clientName, $makeClient] = require __DIR__.'/includes/select-client.php';

$client = $makeClient();

/**
 * Await multiple promises by polling the client tickables.
 *
 * @param  ASKPromiseContract[]  $promises
 * @return array<mixed>
 */
$resolveAll = static function (array $promises, NetworkClientContract $client): array {
    $tickables = $client->tickable();

    do {
        foreach ($tickables as $t) {
            $t->tick(0);
        }
        usleep(1_000);
        $pending = false;
        foreach ($promises as $p) {
            if ($p->getState() === ASKPromiseContract::PENDING) {
                $pending = true;
                break;
            }
        }
    } while ($pending);

    $results = [];
    foreach ($promises as $key => $p) {
        if ($p->getState() === ASKPromiseContract::FULFILLED) {
            $results[$key] = $p->getValue();
        } elseif ($p->getState() === ASKPromiseContract::REJECTED) {
            $results[$key] = $p->getReason();
        } else {
            $results[$key] = new RuntimeException('Promise canceled');
        }
    }

    return $results;
};

$formatOk = require __DIR__.'/includes/format-ok.php';
$formatErr = require __DIR__.'/includes/format-err.php';

$process = static function (array $source, ResponseInterface $response) use ($formatOk, $formatErr): bool {
    try {
        $rate = $source['parseResponse']($response);
        echo $formatOk($source, $rate)."\n";

        return true;
    } catch (Throwable $e) {
        $normalised = [
            'body' => (string) $response->getBody(),
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(),
        ];
        echo $formatErr($source, $e, $normalised)."\n";

        return false;
    }
};

$fire = static function (array $source, NetworkClientContract $client): ASKPromiseContract {
    return $client->request(
        new ASKHttpRequest(url: $source['url'], method: 'GET'),
    );
};

echo "=== NetworkClientContract Batch Processing Example ===\n";
echo "    client: {$clientName}\n\n";

// ---- Batch 1: Fan-out (all sources in parallel) ----
echo "--- Batch 1: Fan-out (all sources in parallel) ---\n";
$start = microtime(true);

$promises = [];
foreach ($sources as $i => $source) {
    $promises[$i] = $fire($source, $client);
}

$allResults = $resolveAll($promises, $client);

$successful = 0;
$failed = 0;
foreach ($allResults as $i => $result) {
    if ($result instanceof Throwable) {
        echo $formatErr($sources[$i], $result, null)."\n";
        $failed++;
        continue;
    }

    if ($process($sources[$i], $result)) {
        $successful++;
    } else {
        $failed++;
    }
}

$elapsed = microtime(true) - $start;
echo "Fan-out result: {$successful} ok, {$failed} failed in ".number_format($elapsed, 3)."s\n\n";

// ---- Batch 2: Chunked (3 at a time) ----
echo "--- Batch 2: Chunked processing (3 at a time) ---\n";
$start = microtime(true);

$chunkSize = 3;
$chunks = array_chunk($sources, $chunkSize, true);
$batchNum = 0;

foreach ($chunks as $chunk) {
    $batchNum++;
    echo "  Chunk #{$batchNum}:\n";

    $promises = [];
    foreach ($chunk as $i => $source) {
        $promises[$i] = $fire($source, $client);
    }

    $chunkResults = $resolveAll($promises, $client);

    foreach ($chunkResults as $i => $result) {
        if ($result instanceof Throwable) {
            echo $formatErr($chunk[$i], $result, null)."\n";
            continue;
        }

        $process($chunk[$i], $result);
    }
}

$elapsed = microtime(true) - $start;
echo "Chunked result: ".number_format($elapsed, 3)."s\n\n";

// ---- Batch 3: Pipeline (first -> second) ----
echo "--- Batch 3: Pipeline (first -> transform -> second) ---\n";
$start = microtime(true);

foreach ([0, 1] as $step => $i) {
    $source = $sources[$i];

    $promise = $fire($source, $client);
    [$result] = $resolveAll([$promise], $client);

    if ($result instanceof Throwable) {
        echo '  Step '.($step + 1).': '.$formatErr($source, $result, null)."\n";
        continue;
    }

    try {
        $rate = $source['parseResponse']($result);
        echo '  Step '.($step + 1).': '.$formatOk($source, $rate)."\n";
    } catch (Throwable $e) {
        $normalised = [
            'body' => (string) $result->getBody(),
            'status' => $result->getStatusCode(),
            'headers' => $result->getHeaders(),
        ];
        echo '  Step '.($step + 1).': '.$formatErr($source, $e, $normalised)."\n";
    }
}

$elapsed = microtime(true) - $start;
echo "Pipeline result: ".number_format($elapsed, 3)."s\n\n";

// ---- Batch 4: Retry on failure (3 attempts) ----
echo "--- Batch 4: Retry on failure (3 attempts) ---\n";
$start = microtime(true);

$attempts = 3;
$slice = array_slice($sources, 0, 3, true);

for ($attempt = 1; $attempt <= $attempts; $attempt++) {
    echo "  Attempt {$attempt}/{$attempts}:\n";
    $anySuccess = false;

    foreach ($slice as $source) {
        $promise = $fire($source, $client);
        [$result] = $resolveAll([$promise], $client);

        if ($result instanceof Throwable) {
            echo $formatErr($source, $result, null)."\n";
            continue;
        }

        if ($process($source, $result)) {
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
