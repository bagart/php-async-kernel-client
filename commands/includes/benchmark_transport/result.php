<?php

declare(strict_types=1);

function render_benchmark_results(
    array $results,
    array $retries,
    array $risks,
    int $runs,
): void {
    echo "=== ASKClient Async Transport Benchmark (CONCURRENT) ===\n";
    echo '    php: '.PHP_VERSION."\n";
    echo "    all sources fetched simultaneously, runs: {$runs} (median)\n";
    echo "    primary: ↓time (faster better) ↑rps (bigger better) ↑score\n\n";

    $header = sprintf(
        "%-14s %9s %9s %6s %7s %7s %5s",
        'transport',
        'time↓',
        'rps↑',
        'ok',
        'fail',
        'memΔ',
        'score',
    );
    echo $header."\n";
    echo str_repeat('-', strlen($header))."\n";

    foreach ($results as $transport => $r) {
        if ($r['error'] !== null) {
            echo sprintf("%-14s ERROR: %s\n", $transport, substr((string)$r['error'], 0, 50));
            continue;
        }
        $delta = $r['peak_memory_delta'] ?? null;
        $deltaFmt = ($delta !== null && $delta > 0) ? number_format($delta / 1024 / 1024, 1).'M' : '—';
        $errStr = $r['fail'] > 0 ? (string)$r['fail'] : '—';
        echo sprintf(
            "%-14s %9.3f %9.2f %6d %7s %7s %5d\n",
            $transport,
            $r['concurrent_time'],
            (float)($r['rps'] ?? 0.0),
            $r['ok'],
            $errStr,
            $deltaFmt,
            $r['fairness'],
        );
    }

    if ($retries !== []) {
        echo "\nRetries:\n";
        foreach ($retries as $name) {
            echo "  {$name}: worker failed on first attempt, retried successfully\n";
        }
    }

    if ($risks !== []) {
        echo "\nRISKS — URLs with >50% time difference across transports:\n";
        echo "  (may indicate external issues — consider removing from benchmark)\n\n";
        foreach ($risks as $i => $risk) {
            echo sprintf("  %d. %s\n", $i + 1, $risk['name']);
            echo "     url: {$risk['url']}\n";
            echo "     max/min ratio: ".number_format($risk['ratio'], 2)."x\n";
            foreach ($risk['times'] as $tName => $tVal) {
                echo sprintf("       %-14s %s\n", $tName, number_format($tVal, 4).'s');
            }
            echo "\n";
        }
    }

    echo "\nRanked by time (fastest → slowest):\n";
    $ranked = array_filter($results, static fn ($r) => $r['error'] === null);
    uasort($ranked, static fn ($a, $b) => $a['concurrent_time'] <=> $b['concurrent_time']);
    $rank = 0;
    foreach ($ranked as $transport => $r) {
        $rank++;
        $mem = $r['peak_memory'] ?? 0;
        $memFmt = $mem > 0 ? number_format($mem / 1024 / 1024, 1).' MB' : '—';
        $delta = $r['peak_memory_delta'] ?? null;
        $deltaFmt = ($delta !== null && $delta > 0) ? number_format($delta / 1024 / 1024, 1).' MB Δ' : '';
        $errNote = $r['fail'] > 0 ? " ({$r['fail']} errors)" : '';
        echo sprintf(
            "  %d. %-14s %.3fs (%.1f rps, fairness %d/100, mem %s%s)%s\n",
            $rank,
            $transport,
            $r['concurrent_time'],
            (float)($r['rps'] ?? 0.0),
            $r['fairness'],
            $memFmt,
            $deltaFmt !== '' ? ', '.$deltaFmt : '',
            $errNote,
        );
    }
}

// CLI entry point (when run directly, not included)
if (PHP_SAPI === 'cli' && (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1) === [])) {
    $storageDir = $argv[1] ?? null;
    if ($storageDir === null) {
        fwrite(STDERR, "Usage: php result.php <storage-dir>\n");
        exit(1);
    }

    $metaFile = $storageDir.'/meta.json';
    $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : [];
    $runs = (int)($meta['runs'] ?? 1);

    $results = [];
    $retries = [];

    $dir = new DirectoryIterator($storageDir);
    foreach ($dir as $entry) {
        if (!$entry->isDir() || $entry->isDot()) {
            continue;
        }
        $t = $entry->getFilename();
        $jsonFile = $entry->getPathname().'/result.json';
        if (!file_exists($jsonFile)) {
            continue;
        }

        $output = trim((string)file_get_contents($jsonFile));
        $workerResult = json_decode($output, true);
        if (!is_array($workerResult) || !isset($workerResult['concurrent_time'])) {
            $results[$t] = ['error' => 'Invalid JSON in '.$jsonFile];
            continue;
        }

        $results[$t] = [
            'concurrent_time' => $workerResult['concurrent_time'],
            'rps' => $workerResult['rps'] ?? 0.0,
            'ok' => $workerResult['ok'] ?? 0,
            'fail' => $workerResult['fail'] ?? 0,
            'total' => $workerResult['total'] ?? 0,
            'wall' => 0.0,
            'peak_memory' => $workerResult['peak_memory'] ?? 0,
            'peak_memory_delta' => $workerResult['peak_memory_delta'] ?? null,
            'baseline_memory' => $workerResult['baseline_memory'] ?? null,
            'fairness' => $workerResult['fairness'] ?? 0,
            'cv' => $workerResult['cv'] ?? 0.0,
            'per_url' => $workerResult['per_url'] ?? [],
            'stderr' => '',
            'error' => null,
        ];
        if ($workerResult['retried'] ?? false) {
            $retries[] = $t;
        }
    }

    if ($results === []) {
        fwrite(STDERR, "No valid result.json files found in {$storageDir}\n");
        exit(1);
    }

    $urlData = [];
    foreach ($results as $transport => $r) {
        if ($r['error'] !== null) {
            continue;
        }
        foreach ($r['per_url'] as $entry) {
            $key = $entry['url'];
            if (!isset($urlData[$key])) {
                $urlData[$key] = ['name' => $entry['name'], 'url' => $entry['url'], 'times' => []];
            }
            $urlData[$key]['times'][$transport] = $entry['avg_time'];
        }
    }

    $risks = [];
    foreach ($urlData as $key => $data) {
        $times = $data['times'];
        if (count($times) < 2) {
            continue;
        }
        $max = max($times);
        $min = min($times);
        if ($min > 0 && ($max / $min) > 1.5) {
            $risks[] = [
                'name' => $data['name'],
                'url' => $data['url'],
                'times' => $times,
                'ratio' => $max / $min,
                'max_transport' => array_search($max, $times, true),
                'min_transport' => array_search($min, $times, true),
            ];
        }
    }
    usort($risks, fn ($a, $b) => $b['ratio'] <=> $a['ratio']);
    $risks = array_slice($risks, 0, 5);

    render_benchmark_results($results, $retries, $risks, $runs);
    echo "\nDone.\n";
}
