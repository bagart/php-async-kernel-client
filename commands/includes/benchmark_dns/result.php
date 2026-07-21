<?php

declare(strict_types=1);

function render_dns_benchmark_results(
    array $results,
    array $retries,
    int $runs,
): void {
    echo "=== ASKClient Async DNS Benchmark (CONCURRENT) ===\n";
    echo '    php: '.PHP_VERSION."\n";
    echo "    all hosts resolved simultaneously, runs: {$runs} (median)\n";
    echo "    primary: ↓time (faster better) ↑rps (bigger better) ↑score\n\n";

    $header = sprintf(
        "%-16s %9s %9s %6s %7s %7s %5s",
        'adapter',
        'time↓',
        'rps↑',
        'ok',
        'fail',
        'memΔ',
        'score',
    );
    echo $header."\n";
    echo str_repeat('-', strlen($header))."\n";

    foreach ($results as $adapter => $r) {
        if ($r['error'] !== null) {
            echo sprintf("%-16s ERROR: %s\n", $adapter, substr((string)$r['error'], 0, 50));
            continue;
        }
        $delta = $r['peak_memory_delta'] ?? null;
        $deltaFmt = ($delta !== null && $delta > 0) ? number_format($delta / 1024 / 1024, 1).'M' : '—';
        $errStr = $r['fail'] > 0 ? (string)$r['fail'] : '—';
        echo sprintf(
            "%-16s %9.4f %9.2f %6d %7s %7s %5d\n",
            $adapter,
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

    echo "\nRanked by time (fastest → slowest):\n";
    $ranked = array_filter($results, static fn ($r) => $r['error'] === null);
    uasort($ranked, static fn ($a, $b) => $a['concurrent_time'] <=> $b['concurrent_time']);
    $rank = 0;
    foreach ($ranked as $adapter => $r) {
        $rank++;
        $mem = $r['peak_memory'] ?? 0;
        $memFmt = $mem > 0 ? number_format($mem / 1024 / 1024, 1).' MB' : '—';
        $delta = $r['peak_memory_delta'] ?? null;
        $deltaFmt = ($delta !== null && $delta > 0) ? number_format($delta / 1024 / 1024, 1).' MB Δ' : '';
        $errNote = $r['fail'] > 0 ? " ({$r['fail']} errors)" : '';
        echo sprintf(
            "  %d. %-16s %.4fs (%.1f rps, fairness %d/100, mem %s%s)%s\n",
            $rank,
            $adapter,
            $r['concurrent_time'],
            (float)($r['rps'] ?? 0.0),
            $r['fairness'],
            $memFmt,
            $deltaFmt !== '' ? ', '.$deltaFmt : '',
            $errNote,
        );
    }
}

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
            'per_host' => $workerResult['per_host'] ?? [],
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

    render_dns_benchmark_results($results, $retries, $runs);
    echo "\nDone.\n";
}
