<?php

declare(strict_types=1);

use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\ASKClient\Transporting\TransportRegistry;
use BAGArt\AsyncKernel\AsyncKernel;
use BAGArt\AsyncKernel\Daemons\ASKFnDaemon;
use BAGArt\AsyncKernel\Daemons\ASKFnDaemonContext;
use BAGArt\AsyncKernel\Exceptions\ASKTechnicalException;
use BAGArt\AsyncKernel\Promise\ASKPromiseResolver;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;

/**
 * ASKClient async benchmark — master/worker architecture.
 *
 * Tests how each transport handles N concurrent requests to different domains.
 * All sources from currency-sources.php are fetched in chunks limited by
 * --concurrent. The metric is total time to complete all sources and RPS.
 *
 * MASTER MODE (no --transport):
 *   Spawns all workers in parallel. Each worker runs in isolation.
 *
 * WORKER MODE (--transport=<name>):
 *   Runs the benchmark for a single transport, outputs JSON result to stdout.
 *
 * Usage:
 *   php commands/benchmark.php                        # master: spawns workers
 *   php commands/benchmark.php --transport=guzzle     # worker: single transport
 *   php commands/benchmark.php --concurrent=20
 *   php commands/benchmark.php --runs=3
 *   php commands/benchmark.php --format=json          # master outputs JSON
 *   php commands/benchmark.php --help
 */

require_once __DIR__.'/../../../../vendor/autoload.php';

$definedOptions = ['transport::', 'runs::', 'format::', 'timeout::', 'seed::', 'help'];

$options = getopt('', $definedOptions);

if (isset($options['help'])) {
    $transports = implode(', ', TransportRegistry::build()->types());
    echo "Usage:
php commands/benchmark.php                        # master: spawns workers
php commands/benchmark.php --transport=guzzle     # worker: single transport
php commands/benchmark.php --runs=1               # repeats, median kept
php commands/benchmark.php --format=json          # master outputs JSON
php commands/benchmark.php --timeout=600          # worker timeout in seconds

Options:
  --transport=<type>                   one of: {$transports} (default: master)
  --runs=N                             repeats, median kept (default: 1)
  --format=<text|json>                 output format (default: text)
  --timeout=N                          worker timeout in seconds (default: 120)
  --seed=N                             worker RNG seed (master auto-assigns per transport)
  --help

All sources from currency-sources.php are fetched simultaneously.
The metric is total time to complete all requests and RPS.
";
    exit(0);
}

$runs = max(1, (int)($options['runs'] ?? 1));
$outputFormat = (string)($options['format'] ?? 'text');
$workerTimeout = max(10, (int)($options['timeout'] ?? 6000));

$registry = TransportRegistry::build();
$transportFilter = (string)($options['transport'] ?? '');

if ($transportFilter === '') {
    $transports = $registry->types();
    echo "Benchmark initiated for: ".implode(', ', $transports).PHP_EOL;

    $phpBin = PHP_BINARY;
    $script = __FILE__;

    $tmpDir = sys_get_temp_dir().'/askbench-'.date('Y-m-d_H-i-s').'-'.bin2hex(random_bytes(8));
    if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
        throw new ASKTechnicalException("Directory $tmpDir was not created");
    }

    $processes = [];
    foreach ($transports as $name) {
        $outPath = $tmpDir.'/'.preg_replace('/[^a-z0-9_-]/i', '_', $name).'.out';
        $errPath = $tmpDir.'/'.preg_replace('/[^a-z0-9_-]/i', '_', $name).'.err';

        $pipes = [];
        $process = @proc_open(
            sprintf(
                '%s %s --transport=%s --runs=%d --seed=%d',
                escapeshellarg($phpBin),
                escapeshellarg($script),
                escapeshellarg($name),
                $runs,
                crc32($name),
            ),
            [
                0 => ['pipe', 'r'],
                1 => ['file', $outPath, 'w'],
                2 => ['file', $errPath, 'w'],
            ],
            $pipes
        );
        if (!is_resource($process)) {
            $processes[$name] = ['error' => 'Failed to spawn process'];
            continue;
        }
        fclose($pipes[0]);

        $outFh = @fopen($outPath, 'rb');
        $errFh = @fopen($errPath, 'rb');
        if ($outFh === false || $errFh === false) {
            @proc_terminate($process, 9);
            @proc_close($process);
            $processes[$name] = ['error' => 'Failed to open read handle on temp output'];
            continue;
        }
        fseek($outFh, 0, SEEK_END);
        fseek($errFh, 0, SEEK_END);

        $processes[$name] = [
            'process' => $process,
            'out_path' => $outPath,
            'err_path' => $errPath,
            'out_fh' => $outFh,
            'err_fh' => $errFh,
            'stdout_buf' => '',
            'stderr_buf' => '',
            'start' => microtime(true),
            'done' => false,
            'timed_out' => false,
            'retried' => false,
            'exit_code' => null,
        ];
    }

    $globalStart = microtime(true);

    $drainFile = static function ($fh): string {
        if (!is_resource($fh)) {
            return '';
        }

        return (string)@stream_get_contents($fh);
    };

    while (true) {
        $elapsed = microtime(true) - $globalStart;
        if ($elapsed > $workerTimeout) {
            foreach ($processes as $name => &$p) {
                if (!$p['done']) {
                    @proc_terminate($p['process'], 9);
                    $p['stdout_buf'] .= $drainFile($p['out_fh']);
                    $p['stderr_buf'] .= $drainFile($p['err_fh']);
                    @fclose($p['out_fh']);
                    @fclose($p['err_fh']);
                    @proc_close($p['process']);
                    $p['done'] = true;
                    $p['timed_out'] = true;
                }
            }
            unset($p);
            break;
        }

        foreach ($processes as $name => &$p) {
            if ($p['done'] || !isset($p['process']) || !is_resource($p['process'])) {
                continue;
            }
            $p['stdout_buf'] .= $drainFile($p['out_fh']);
            $p['stderr_buf'] .= $drainFile($p['err_fh']);

            $status = @proc_get_status($p['process']);
            if ($status === false || !$status['running']) {
                $p['stdout_buf'] .= $drainFile($p['out_fh']);
                $p['stderr_buf'] .= $drainFile($p['err_fh']);
                @fclose($p['out_fh']);
                @fclose($p['err_fh']);
                @proc_close($p['process']);
                $p['done'] = true;
                $p['exit_code'] = $status ? $status['exitcode'] : -1;
                $p['wall'] = microtime(true) - $p['start'];
            }
        }
        unset($p);

        $allDone = true;
        foreach ($processes as $p) {
            if (!$p['done'] && !isset($p['error'])) {
                $allDone = false;
                break;
            }
        }
        if ($allDone) {
            break;
        }
    }

    // Cleanup temp files after all workers have drained and closed.
    foreach ($processes as $p) {
        if (isset($p['out_path'])) {
            @unlink($p['out_path']);
        }
        if (isset($p['err_path'])) {
            @unlink($p['err_path']);
        }
    }
    @rmdir($tmpDir);

    $results = [];
    $allPerUrl = [];
    $retries = [];

    foreach ($processes as $name => $p) {
        if (isset($p['error'])) {
            $results[$name] = ['error' => $p['error']];
            continue;
        }

        if ($p['timed_out'] ?? false) {
            $results[$name] = ['error' => "Timed out after {$workerTimeout}s"];
            continue;
        }

        $output = trim($p['stdout_buf']);
        $stderr = trim($p['stderr_buf']);

        if ($output === '') {
            $results[$name] = [
                'error' => 'No output. Stderr: '.substr($stderr, 0, 200),
            ];
            continue;
        }

        $workerResult = json_decode($output, true);

        if (!is_array($workerResult) || !isset($workerResult['concurrent_time'])) {
            $results[$name] = ['error' => 'Invalid JSON: '.substr($output, 0, 200)];
            continue;
        }

        $mem = $workerResult['peak_memory'] ?? 0;
        $ok = $workerResult['ok'] ?? 0;
        $fail = $workerResult['fail'] ?? 0;

        $results[$name] = [
            'concurrent_time' => $workerResult['concurrent_time'],
            'rps' => $workerResult['rps'] ?? 0.0,
            'ok' => $ok,
            'fail' => $fail,
            'total' => $workerResult['total'] ?? ($ok + $fail),
            'wall' => $p['wall'] ?? 0,
            'peak_memory' => $mem,
            'peak_memory_delta' => $workerResult['peak_memory_delta'] ?? null,
            'baseline_memory' => $workerResult['baseline_memory'] ?? null,
            'fairness' => $workerResult['fairness'] ?? 0,
            'cv' => $workerResult['cv'] ?? 0.0,
            'per_url' => $workerResult['per_url'] ?? [],
            'stderr' => $stderr,
            'error' => null,
        ];

        if ($workerResult['retried'] ?? false) {
            $retries[] = $name;
        }
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

    if ($outputFormat === 'json') {
        echo json_encode([
                'results' => $results,
                'risks' => $risks,
                'retries' => $retries,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }

    require __DIR__.'/includes/benchmark/result.php';
    render_benchmark_results($results, $retries, $risks, $runs);

    echo "\nDone.\n";
    exit(0);
}

if (!$registry->has($transportFilter)) {
    fwrite(STDERR, "Unknown transport: {$transportFilter}\n");
    exit(2);
}

$makeTransport = fn () => $registry->make($transportFilter);
$sources = require __DIR__.'/includes/currency-sources.php';
$baselineMemory = memory_get_usage(true);

mt_srand(isset($options['seed']) ? (int)$options['seed'] : 0);
shuffle($sources);
mt_srand();

fwrite(STDERR, sprintf(
    "[benchmark] transport=%s sources=%d runs=%d\n",
    $transportFilter,
    count($sources),
    $runs,
));

$runConcurrent = static function (string $transportName, callable $makeTransport, array $sources): array {
    $sources = array_values($sources);
    $total = count($sources);

    $logger = new ASKLogWrapper(minLevel: 'error');
    $kernel = new AsyncKernel($logger, shutdownTimeout: 30);

    $transport = $makeTransport();
    $resolver = new ASKPromiseResolver();
    $kernel->addTickable($transport);
    $kernel->addTickable($resolver);

    $ok = 0;
    $failed = 0;
    $perUrl = [];
    $reqTimes = [];

    $context = new ASKFnDaemonContext(daemonName: 'bench', logger: $logger);
    $daemon = new ASKFnDaemon(
        daemonContext: $context,
        fnProduce: function (ASKFnDaemonContext $context) use (
            $transportName,
            $transport,
            $resolver,
            $kernel,
            $sources,
            $total,
            &$ok,
            &$failed,
            &$perUrl,
            &$reqTimes
        ): void {
            $promises = $fireTimes = [];
            $done = 0;

            try {
                foreach ($sources as $source) {
                    $fireTimes[] = microtime(true);
                    $promises[] = $transport->requestAsync(
                        new ASKHttpRequest(url: $source['url'], method: 'GET'),
                    );
                }

                foreach ($promises as $idx => $promise) {
                    try {
                        $resolver->await($promise);
                        $elapsed = microtime(true) - $fireTimes[$idx];
                        $ok++;
                        $source = $sources[$idx];
                        $key = $source['url'];
                        if (!isset($perUrl[$key])) {
                            $perUrl[$key] = [
                                'name' => trim($source['name']),
                                'url' => $source['url'],
                                'times' => [],
                            ];
                        }
                        $perUrl[$key]['times'][] = $elapsed;
                        $reqTimes[] = $elapsed;
                    } catch (\Throwable) {
                        $failed++;
                    }
                    $done++;
                    fwrite(STDERR, sprintf(
                        "\r  [%s] %d/%d complete (%d fails)     ",
                        $transportName,
                        $done,
                        $total,
                        $failed,
                    ));
                }
                fwrite(STDERR, "\n");
            } finally {
                $kernel->stop('concurrent complete');
            }
        },
        fnCanProduce: fn (ASKFnDaemonContext $context): bool => !($context->payload['done'] ?? false),
        fnShutdown: function (ASKFnDaemonContext $context, mixed $shutdownContext = null): bool {
            $context->payload['done'] = true;

            return true;
        },
    );

    $kernel->addDaemon($daemon, 0);
    $netStart = microtime(true);
    $kernel->run();
    $netTime = microtime(true) - $netStart;

    return [$netTime, $ok, $failed, $perUrl, $reqTimes];
};

$median = static function (array $values): float {
    sort($values);
    $n = count($values);
    if ($n === 0) {
        return 0.0;
    }
    if ($n % 2 === 1) {
        return (float)$values[(int)($n / 2)];
    }

    return ((float)$values[$n / 2 - 1] + (float)$values[$n / 2]) / 2;
};

$stddev = static function (array $values): float {
    $n = count($values);
    if ($n < 2) {
        return 0.0;
    }
    $mean = array_sum($values) / $n;
    $variance = 0.0;
    foreach ($values as $v) {
        $variance += ($v - $mean) ** 2;
    }

    return sqrt($variance / ($n - 1));
};

$fairnessScore = static function (float $cv, float $successRate): int {
    $consistencyPts = max(0, 50 * (1 - $cv / 1.0));
    $reliabilityPts = $successRate * 50;

    return (int)round($consistencyPts + $reliabilityPts);
};

$concurrentTimes = [];
$allChunkTimes = [];
$oks = 0;
$faileds = 0;
$allPerUrl = [];
$retried = false;

for ($attempt = 0; $attempt < 2; $attempt++) {
    $concurrentTimes = [];
    $allReqTimes = [];
    $oks = 0;
    $faileds = 0;
    $allPerUrl = [];
    $workerError = null;

    try {
        for ($r = 0; $r < $runs; $r++) {
            if ($runs > 1) {
                 sleep(5);
                fwrite(STDERR, sprintf(
                    "  [%s] run %d/%d starting%s\n",
                    $transportFilter,
                    $r + 1,
                    $runs,
                    $attempt > 0 ? ' (retry)' : '',
                ));
            }
            [$t, $ok, $failed, $perUrl, $reqTimes] = $runConcurrent(
                $transportFilter,
                $makeTransport,
                $sources,
            );
            $concurrentTimes[] = $t;
            $allReqTimes[] = $reqTimes;
            $oks += $ok;
            $faileds += $failed;

            foreach ($perUrl as $key => $data) {
                if (!isset($allPerUrl[$key])) {
                    $allPerUrl[$key] = ['name' => $data['name'], 'url' => $data['url'], 'times' => []];
                }
                $allPerUrl[$key]['times'] = array_merge($allPerUrl[$key]['times'], $data['times']);
            }
        }
    } catch (\Throwable $e) {
        $workerError = $e->getMessage();
        if ($attempt === 0) {
            $retried = true;
            continue;
        }
        fwrite(STDERR, "Worker failed after retry: {$workerError}\n");
        exit(3);
    }

    break;
}

$concurrentTime = $median($concurrentTimes);
$total = $oks + $faileds;
$successRate = $total > 0 ? $oks / $total : 0.0;

// CV across all request times
$flatReqTimes = array_merge(...$allReqTimes);
$cv = $stddev($flatReqTimes) / max($flatReqTimes ?: [0.001]);
$fairness = $fairnessScore($cv, $successRate);
$peakMem = memory_get_peak_usage(true);
$peakMemDelta = max(0, $peakMem - $baselineMemory);

$rps = $concurrentTime > 0 ? $total / $concurrentTime : 0.0;

$perUrlOut = [];
foreach ($allPerUrl as $key => $data) {
    $avgTime = count($data['times']) > 0 ? array_sum($data['times']) / count($data['times']) : 0;
    $perUrlOut[] = [
        'name' => $data['name'],
        'url' => $data['url'],
        'avg_time' => round($avgTime, 6),
        'samples' => count($data['times']),
    ];
}

echo json_encode([
        'concurrent_time' => $concurrentTime,
        'ok' => $oks,
        'fail' => $faileds,
        'total' => $total,
        'rps' => round($rps, 2),
        'fairness' => $fairness,
        'cv' => round($cv, 4),
        'peak_memory' => $peakMem,
        'peak_memory_delta' => $peakMemDelta,
        'baseline_memory' => $baselineMemory,
        'per_url' => $perUrlOut,
        'retried' => $retried,
    ], JSON_THROW_ON_ERROR)."\n";
