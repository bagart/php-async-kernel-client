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
    'help',
];

$options = CliActions::parseOptions(
    getopt('', $definedOptions),
    $definedOptions
);

CliActions::initRuntime($options);

if (isset($options['help'])) {
    echo "Usage:
php commands/example-daemon.php                   # Default: fetch every 1s
php commands/example-daemon.php --interval=5      # Fetch every 5s

Options:
  --interval=N                        Seconds between API calls (default: 1)
  --memory-limit=512M                 PHP memory limit (default: 512M)
  --log-level=debug|info|warning|error  minimum log level (default: info)
  --transport=curl|guzzle             HTTP transport (default: curl)
  --help
";

    exit(0);
}

$interval = (int)($options['interval'] ?? 1);
$logLevel = (string)($options['log-level'] ?? null) ?: ASKLogWrapper::LEVEL_DEFAULT;

// Forward the parsed --transport to select-transport.php via env, since the
// selector calls getopt() itself and this daemon already consumed argv.
if (is_string($options['transport'] ?? null) && $options['transport'] !== '') {
    putenv('ASK_TRANSPORT='.$options['transport']);
}

$sources = require __DIR__.'/includes/currency-sources.php';
[$transportName, $makeTransport] = require __DIR__.'/includes/select-transport.php';

$kernelLogger = new ASKLogWrapper(minLevel: $logLevel);

$client = new ASKClient(
    transport: $makeTransport(),
);

$kernel = new AsyncKernel($kernelLogger);

$kernel
    ->addDaemon(
        daemon: new ASKFnDaemon(
            daemonContext: new ASKFnDaemonContext(
                daemonName: 'ask-client-daemon',
                logger: $kernelLogger,
            ),
            fnProduce: function (ASKFnDaemonContext $context) use ($sources, $client): void {
                $source = $sources[array_rand($sources)];

                $client
                    ->execute(new ASKHttpRequest(
                        url: $source['url'],
                        method: 'GET',
                    ))
                    ->then(function ($response) use ($context, $source) {
                        $rate = $source['parseResponse']($response);
                        echo date('H:i:s') . ' '
                            . str_pad("[ASKClient Daemon: {$source['name']}]", 50)
                            . " USD/EUR: {$rate}\n";
                    })
                    ->catch(function (\Throwable $e) use ($context, $source) {
                        $context->logger->debug(
                            "[{$context->daemonName}] rejected [{$source['name']}]: "
                            . $e::class . ": {$e->getMessage()}"
                        );
                    })
                    ->await();
            },
            fnCanProduce: function (ASKFnDaemonContext $context): bool {
                return true;
            },
            fnStartup: function (ASKFnDaemonContext $context) use ($transportName): void {
                $context->logger->info("{$context->daemonName} started (transport: {$transportName}).");
            },
            fnShutdown: function (ASKFnDaemonContext $context, mixed $shutdownContext = null): bool {
                $context->logger->info("{$context->daemonName} stopped.");
                return true;
            },
            fnError: function (\Throwable $e, ASKFnDaemonContext $context): void {
                $context->logger->error("[{$context->daemonName}] error: " . $e->getMessage());
            },
        ),
        producerInterval: $interval
    )
    ->run();
