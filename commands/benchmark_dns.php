<?php

declare(strict_types=1);

use BAGArt\ASKClient\Dns\AskDnsConfig;
use BAGArt\ASKClient\Dns\AskDnsRegistry;
use BAGArt\AsyncKernel\Exceptions\ASKTechnicalException;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;

/**
 * ASKClient async DNS benchmark — master/worker architecture.
 *
 * Tests how fast each DNS adapter resolves a batch of hostnames concurrently.
 * Sources are extracted from currency-sources.php (only the domain part).
 * All hosts are resolved simultaneously within each adapter.
 * The metric is total time to resolve all hosts and RPS.
 *
 * MASTER MODE (no --adapter):
 *   Spawns all workers in parallel. Each worker runs in isolation.
 *
 * WORKER MODE (--adapter=<type>):
 *   Runs the benchmark for a single DNS adapter type.
 *
 * Usage:
 *   php commands/benchmark_dns.php                          # master: spawns workers
 *   php commands/benchmark_dns.php --adapter=ask-kernel     # worker: single adapter
 *   php commands/benchmark_dns.php --runs=3
 *   php commands/benchmark_dns.php --format=json            # master outputs JSON
 *   php commands/benchmark_dns.php --help
 */

require_once __DIR__.'/../../../../vendor/autoload.php';

$registry = AskDnsRegistry::build();
$adapterTypes = $registry->types();

$definedOptions = ['adapter::', 'runs::', 'format::', 'timeout::', 'seed::', 'dns-use-tls::', 'tls::', 'help', 'list'];

$options = getopt('', $definedOptions);

if (isset($options['help'])) {
    $adapters = implode(', ', $adapterTypes);
    echo "Usage:
php commands/benchmark_dns.php                          # master: spawns workers
php commands/benchmark_dns.php --adapter=ask-kernel     # worker: single adapter
php commands/benchmark_dns.php --runs=1                 # repeats, median kept
php commands/benchmark_dns.php --format=json            # master outputs JSON
php commands/benchmark_dns.php --timeout=300            # worker timeout in seconds
php commands/benchmark_dns.php --list                   # list available adapters

Adapter types (--adapter):
  {$adapters}

Options:
  --adapter=<type>               one of: {$adapters} (default: master)
  --runs=N                       repeats, median kept (default: 1)
  --format=<text|json>           output format (default: text)
  --timeout=N                    worker timeout in seconds (default: 120)
  --seed=N                       worker RNG seed (master auto-assigns per adapter)
  --tls=<true|false>             enable TLS tests (default: true)
  --dns-use-tls=<true|false>     worker: use DNS over TLS (default: true)
  --help
  --list
";
    exit(0);
}

if (isset($options['list'])) {
    echo "Available DNS adapters:\n";
    foreach ($adapterTypes as $name) {
        $tls = $registry->supportsTls($name) ? 'yes' : 'no';
        echo sprintf("  %-16s TLS: %s\n", $name, $tls);
    }
    exit(0);
}

$runs = max(1, (int)($options['runs'] ?? 1));
$outputFormat = (string)($options['format'] ?? 'text');
$workerTimeout = max(10, (int)($options['timeout'] ?? 6000));

$adapterFilter = (string)($options['adapter'] ?? '');

if ($adapterFilter === '') {
    $tlsEnabled = !isset($options['tls']) || filter_var($options['tls'], FILTER_VALIDATE_BOOLEAN);

    // Build task list: TLS first (most important), then non-TLS
    $tasks = [];
    foreach ($adapterTypes as $name) {
        if ($tlsEnabled && $registry->supportsTls($name)) {
            $tasks[] = ['adapter' => $name, 'label' => $name.' (tls)', 'useTls' => true];
        }
        $tasks[] = ['adapter' => $name, 'label' => $name, 'useTls' => false];
    }

    echo "Benchmark initiated for: ".implode(', ', $adapterTypes).PHP_EOL;

    $phpBin = PHP_BINARY;
    $script = __FILE__;

    $tmpDir = sys_get_temp_dir().'/askdnsbench-'.date('Y-m-d_H-i-s').'-'.bin2hex(random_bytes(8));
    if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
        throw new ASKTechnicalException("Directory $tmpDir was not created");
    }

    $processes = [];
    foreach ($tasks as $task) {
        $safeLabel = preg_replace('/[^a-z0-9_-]/i', '_', $task['label']);
        $outPath = $tmpDir.'/'.$safeLabel.'.out';
        $errPath = $tmpDir.'/'.$safeLabel.'.err';

        $pipes = [];
        $process = @proc_open(
            sprintf(
                '%s %s --adapter=%s --runs=%d --seed=%d --dns-use-tls=%s',
                escapeshellarg($phpBin),
                escapeshellarg($script),
                escapeshellarg($task['adapter']),
                $runs,
                crc32($task['label']),
                $task['useTls'] ? '1' : '0',
            ),
            [
                0 => ['pipe', 'r'],
                1 => ['file', $outPath, 'w'],
                2 => ['file', $errPath, 'w'],
            ],
            $pipes
        );
        if (!is_resource($process)) {
            $processes[$task['label']] = ['error' => 'Failed to spawn process'];
            continue;
        }
        fclose($pipes[0]);

        $outFh = @fopen($outPath, 'rb');
        $errFh = @fopen($errPath, 'rb');
        if ($outFh === false || $errFh === false) {
            @proc_terminate($process, 9);
            @proc_close($process);
            $processes[$task['label']] = ['error' => 'Failed to open read handle on temp output'];
            continue;
        }
        fseek($outFh, 0, SEEK_END);
        fseek($errFh, 0, SEEK_END);

        $processes[$task['label']] = [
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
            'per_host' => $workerResult['per_host'] ?? [],
            'stderr' => $stderr,
            'error' => null,
        ];

        if ($workerResult['retried'] ?? false) {
            $retries[] = $name;
        }
    }

    $geoData = collectGeoData();

    if ($outputFormat === 'json') {
        echo json_encode([
            'results' => $results,
            'retries' => $retries,
            'geo' => $geoData,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }

    require __DIR__.'/includes/benchmark_dns/result.php';
    render_dns_benchmark_results($results, $retries, $runs);

    if ($geoData !== [] && !isset($geoData['error'])) {
        echo "\n--- Geo location ---\n";
        echo "  Country: {$geoData['country']}, ISP: {$geoData['isp']}".(isset($geoData['ip']) ? " ({$geoData['ip']})" : '')."\n";
    }

    echo "\nDone.\n";
    exit(0);
}

/**
 * Collect IP geolocation data via the ip_geolocation command.
 *
 * @return array{ip?: string, country?: string, isp?: string, error?: string}
 */
function collectGeoData(): array
{
    $phpBin = PHP_BINARY;
    $geoScript = __DIR__.'/ip_geolocation.php';

    $pipes = [];
    $process = @proc_open(
        sprintf('%s %s --json --no-ip', escapeshellarg($phpBin), escapeshellarg($geoScript)),
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        return ['error' => 'Failed to spawn geo process'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || $stdout === false || trim($stdout) === '') {
        return ['error' => "Geo process failed (exit: {$exitCode})"];
    }

    $decoded = json_decode(trim($stdout), true);

    return is_array($decoded) ? $decoded : ['error' => 'Invalid geo JSON response'];
}

if (!$registry->has($adapterFilter)) {
    fwrite(STDERR, "Unknown adapter: {$adapterFilter}\n");
    exit(2);
}

$currencySources = require __DIR__.'/includes/currency-sources.php';

$seenHosts = [];
$sources = [];
foreach ($currencySources as $source) {
    $host = parse_url($source['url'], PHP_URL_HOST);
    if ($host === false || $host === null || $host === '') {
        continue;
    }
    $hostKey = strtolower($host);
    if (isset($seenHosts[$hostKey])) {
        continue;
    }
    $seenHosts[$hostKey] = true;
    $sources[] = [
        'host' => $host,
        'name' => $source['name'],
    ];
}

$baselineMemory = memory_get_usage(true);

mt_srand(isset($options['seed']) ? (int)$options['seed'] : 0);
shuffle($sources);
mt_srand();

fwrite(STDERR, sprintf(
    "[benchmark_dns] adapter=%s hosts=%d runs=%d\n",
    $adapterFilter,
    count($sources),
    $runs,
));

$dnsConfig = AskDnsConfig::fromOptions($options);

$runResolve = static function (string $adapterType, AskDnsConfig $config, array $sources, float $deadline = 30.0): array {
    $reg = AskDnsRegistry::build(new ASKLogWrapper(
        minLevel: ASKLogWrapper::LEVEL_WARNING,
    ));
    $hosts = array_column($sources, 'host');
    $total = count($hosts);

    $adapter = $reg->make($adapterType, $config);

    $startTimes = [];
    $endTimes = [];
    $results = [];
    $durations = [];
    $pending = [];

    foreach ($sources as $source) {
        $host = $source['host'];
        $start = microtime(true);
        $ip = $adapter->resolve($host);

        if ($ip !== null) {
            $endTimes[$host] = microtime(true);
            $startTimes[$host] = $start;
            $results[$host] = ['ip' => $ip, 'error' => null];
        } else {
            $startTimes[$host] = $start;
            $pending[$host] = true;
        }
    }

    $globalDeadline = microtime(true) + $deadline;

    while ($pending !== [] && microtime(true) < $globalDeadline) {
        $adapter->tick();

        $sockets = $adapter->getReadSockets();

        if ($sockets !== []) {
            $read = $sockets;
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 0, 50000) > 0) {
                foreach ($read as $socket) {
                    $adapter->processReadable($socket);
                }
            }
        }

        $fresh = $adapter->flushFresh();

        foreach ($fresh as $host => $ip) {
            if (isset($pending[$host])) {
                $endTimes[$host] = microtime(true);
                $results[$host] = ['ip' => $ip, 'error' => null];
                unset($pending[$host]);
            }
        }
    }

    $ok = 0;
    $failed = 0;
    $perHost = [];

    foreach ($sources as $source) {
        $host = $source['host'];
        $res = $results[$host] ?? null;

        if ($res !== null && $res['ip'] !== null && $res['error'] === null) {
            $ok++;
            $elapsed = isset($startTimes[$host], $endTimes[$host])
                ? $endTimes[$host] - $startTimes[$host]
                : 0.0;
            $durations[] = $elapsed;
            $perHost[] = [
                'name' => $source['name'],
                'host' => $host,
                'ip' => $res['ip'],
                'time' => $elapsed,
            ];
        } else {
            $failed++;
            $durations[] = 0.0;
            $perHost[] = [
                'name' => $source['name'],
                'host' => $host,
                'ip' => null,
                'time' => 0.0,
                'error' => ($res['error'] ?? 'Timeout'),
            ];
        }
    }

    $concurrentTime = microtime(true) - min($startTimes ?: [microtime(true)]);
    $rps = $concurrentTime > 0 ? $total / $concurrentTime : 0.0;

    return [$concurrentTime, $ok, $failed, $perHost, $durations];
};

$concurrentTimes = [];
$allDurations = [];
$oks = 0;
$faileds = 0;
$allPerHost = [];
$retried = false;

for ($attempt = 0; $attempt < 2; $attempt++) {
    $concurrentTimes = [];
    $allDurations = [];
    $oks = 0;
    $faileds = 0;
    $allPerHost = [];
    $workerError = null;

    try {
        for ($r = 0; $r < $runs; $r++) {
            if ($runs > 1) {
                sleep(5);
                fwrite(STDERR, sprintf(
                    "  [%s] run %d/%d starting%s\n",
                    $adapterFilter,
                    $r + 1,
                    $runs,
                    $attempt > 0 ? ' (retry)' : '',
                ));
            }
            $deadline = (float)($options['timeout'] ?? 30);
            [$t, $ok, $failed, $perHost, $durations] = $runResolve($adapterFilter, $dnsConfig, $sources, $deadline);
            $concurrentTimes[] = $t;
            $allDurations[] = $durations;
            $oks += $ok;
            $faileds += $failed;

            foreach ($perHost as $entry) {
                $host = $entry['host'];
                if (!isset($allPerHost[$host])) {
                    $allPerHost[$host] = [
                        'name' => $entry['name'],
                        'host' => $host,
                        'times' => [],
                        'errors' => [],
                    ];
                }
                $allPerHost[$host]['times'][] = $entry['time'];
                if (isset($entry['error'])) {
                    $allPerHost[$host]['errors'][] = $entry['error'];
                }
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

$concurrentTime = $median($concurrentTimes);
$total = $oks + $faileds;
$successRate = $total > 0 ? $oks / $total : 0.0;

$peakMem = memory_get_peak_usage(true);
$peakMemDelta = max(0, $peakMem - $baselineMemory);

$rps = $concurrentTime > 0 ? $total / $concurrentTime : 0.0;

$perHostOut = [];
foreach ($allPerHost as $host => $data) {
    $avgTime = count($data['times']) > 0 ? array_sum($data['times']) / count($data['times']) : 0;
    $perHostOut[] = [
        'name' => $data['name'],
        'host' => $host,
        'avg_time' => round($avgTime, 6),
        'samples' => count($data['times']),
        'errors' => count($data['errors']),
    ];
}

$flatDurations = array_merge(...$allDurations);
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
$maxDur = max($flatDurations);
$cv = $maxDur > 0.0 ? $stddev($flatDurations) / $maxDur : 0.0;
$fairness = (int)round(max(0, 50 * (1 - $cv / 1.0)) + $successRate * 50);

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
    'per_host' => $perHostOut,
    'retried' => $retried,
], JSON_THROW_ON_ERROR)."\n";
