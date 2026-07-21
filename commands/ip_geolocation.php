<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKClient;
use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\AsyncKernel\AsyncKernel;
use BAGArt\AsyncKernel\CliActions;
use BAGArt\AsyncKernel\Daemons\ASKFnDaemon;
use BAGArt\AsyncKernel\Daemons\ASKFnDaemonContext;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;

require_once __DIR__.'/../../../../vendor/autoload.php';

$definedOptions = [
    'interval::',
    'memory-limit::',
    'log-level::',
    'transport::',
    'json',
    'no-ip',
    'help',
];

$options = CliActions::parseOptions(
    getopt('', $definedOptions),
    $definedOptions
);

CliActions::initRuntime($options);

if (isset($options['help'])) {
    echo "Usage:
php commands/ip_geolocation.php                    # One-shot: print IP, country, ISP
php commands/ip_geolocation.php --interval=60     # Daemon mode: check every 60s
php commands/ip_geolocation.php --json            # JSON output
php commands/ip_geolocation.php --json --no-ip    # JSON without IP field

Options:
  --interval=N                        Daemon mode with N seconds between checks (default: one-shot)
  --memory-limit=512M                 PHP memory limit (default: 512M)
  --log-level=debug|info|warning|error  minimum log level (default: info)
  --transport=curl|guzzle|socket-h1  HTTP transport (default: curl)
  --json                              Output as JSON
  --no-ip                             Omit IP from output
  --help
";
    exit(0);
}

$outputJson = isset($options['json']);
$noIp = isset($options['no-ip']);
$interval = isset($options['interval']) ? (int) $options['interval'] : 0;
$logLevel = (string) ($options['log-level'] ?? null) ?: ASKLogWrapper::LEVEL_DEFAULT;

$transportOpt = is_string($options['transport'] ?? null) ? $options['transport'] : '';
if ($transportOpt !== '') {
    putenv('ASK_TRANSPORT='.$transportOpt);
} elseif (getenv('ASK_TRANSPORT') === false || getenv('ASK_TRANSPORT') === '') {
    putenv('ASK_TRANSPORT=curl');
}

[$transportName, $makeTransport] = require __DIR__.'/includes/select_transport.php';

$kernelLogger = new ASKLogWrapper(minLevel: $logLevel);

$client = new ASKClient(
    transport: $makeTransport(),
);

function fetchGeolocation(ASKClient $client, bool $outputJson, bool $noIp): void
{
    $response = $client
        ->execute(new ASKHttpRequest(
            url: 'http://ip-api.com/json/',
            method: 'GET',
        ))
        ->await();

    if (is_array($response)) {
        $body = $response['body'] ?? '';
    } elseif ($response instanceof \Psr\Http\Message\ResponseInterface) {
        $body = (string) $response->getBody();
    } else {
        $body = (string) $response;
    }
    $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

    if (($data['status'] ?? null) === 'fail') {
        $error = ['error' => $data['message'] ?? 'unknown'];
        if ($outputJson) {
            echo json_encode($error, JSON_THROW_ON_ERROR)."\n";
        } else {
            echo 'Error: '.$error['error']."\n";
        }

        return;
    }

    if ($outputJson) {
        $output = [
            'country' => $data['country'] ?? 'N/A',
            'isp' => $data['isp'] ?? 'N/A',
        ];
        if (!$noIp) {
            $output['ip'] = $data['query'] ?? 'N/A';
        }
        echo json_encode($output, JSON_THROW_ON_ERROR)."\n";
    } else {
        if (!$noIp) {
            echo 'IP:      '.($data['query'] ?? 'N/A')."\n";
        }
        echo 'Country: '.($data['country'] ?? 'N/A')."\n";
        echo 'ISP:     '.($data['isp'] ?? 'N/A')."\n";
    }
}

if ($interval > 0) {
    $kernel = new AsyncKernel($kernelLogger);

    $kernel
        ->addDaemon(
            daemon: new ASKFnDaemon(
                daemonContext: new ASKFnDaemonContext(
                    daemonName: 'ip-geolocation',
                    logger: $kernelLogger,
                ),
                fnProduce: function (ASKFnDaemonContext $context) use ($client, $outputJson, $noIp): void {
                    try {
                        fetchGeolocation($client, $outputJson, $noIp);
                    } catch (\Throwable $e) {
                        $err = ['error' => $e->getMessage()];
                        echo $outputJson ? json_encode($err, JSON_THROW_ON_ERROR)."\n" : 'Error: '.$e->getMessage()."\n";
                    }
                },
                fnCanProduce: fn (ASKFnDaemonContext $context): bool => true,
                fnStartup: function (ASKFnDaemonContext $context) use ($transportName): void {
                    echo "IP Geolocation daemon started (transport: {$transportName}).\n";
                },
                fnShutdown: function (ASKFnDaemonContext $context, mixed $shutdownContext = null): bool {
                    echo "IP Geolocation daemon stopped.\n";

                    return true;
                },
                fnError: function (\Throwable $e, ASKFnDaemonContext $context) use ($outputJson): void {
                    $err = ['error' => $e->getMessage()];
                    echo $outputJson ? json_encode($err, JSON_THROW_ON_ERROR)."\n" : 'Error: '.$e->getMessage()."\n";
                },
            ),
            producerInterval: $interval,
        )
        ->run();
} else {
    fetchGeolocation($client, $outputJson, $noIp);
}
