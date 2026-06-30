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
php commands/ask-client.php                        # Default: fetch every 1s
php commands/ask-client.php --interval=5           # Fetch every 5s
php commands/ask-client.php --transport=guzzle     # Use a specific transport

Options:
  --interval=N                        Seconds between API calls (default: 1)
  --memory-limit=512M                 PHP memory limit (default: 512M)
  --log-level=debug|info|warning|error  minimum log level (default: info)
  --transport=curl|guzzle|socket-h1  HTTP transport (default: promise-curl)
  --help
";
    exit(0);
}

$interval = (int)($options['interval'] ?? 1);
$logLevel = (string)($options['log-level'] ?? null) ?: ASKLogWrapper::LEVEL_DEFAULT;

// Default to promise-socket-h1 (pure PHP sockets, no extensions needed)
$transportOpt = is_string($options['transport'] ?? null) ? $options['transport'] : '';
if ($transportOpt !== '') {
    putenv('ASK_TRANSPORT='.$transportOpt);
} elseif (getenv('ASK_TRANSPORT') === false || getenv('ASK_TRANSPORT') === '') {
    putenv('ASK_TRANSPORT=promise-socket-h1');
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
                daemonName: 'ask-client',
                logger: $kernelLogger,
            ),
            fnProduce: function (ASKFnDaemonContext $context) use ($sources, $client): void {
                $source = $sources[array_rand($sources)];

                try {
                    $response = $client
                        ->execute(new ASKHttpRequest(
                            url: $source['url'],
                            method: 'GET',
                        ))
                        ->await();

                    if (is_array($response)) {
                        echo date('H:i:s').' '
                            .str_pad("[{$source['name']}]", 50)
                            ." HTTP {$response['status']} | body: "
                            .mb_substr($response['body'] ?? '', 0, 200)."\n";

                        return;
                    }

                    $rate = $source['parseResponse']($response);
                    echo date('H:i:s').' '
                        .str_pad("[{$source['name']}]", 50)
                        ." USD/EUR: {$rate}\n";
                } catch (\Throwable $e) {
                    echo date('H:i:s').' '
                        .str_pad("[{$source['name']}]", 50)
                        ." ERROR: {$e->getMessage()}\n";
                }
            },
            fnCanProduce: fn (ASKFnDaemonContext $context): bool => true,
            fnStartup: function (ASKFnDaemonContext $context) use ($transportName): void {
                $context->logger->info("{$context->daemonName} started (transport: {$transportName}).");
            },
            fnShutdown: function (ASKFnDaemonContext $context, mixed $shutdownContext = null): bool {
                $context->logger->info("{$context->daemonName} stopped.");

                return true;
            },
            fnError: function (\Throwable $e, ASKFnDaemonContext $context): void {
                $context->logger->error("[{$context->daemonName}] error: ".$e->getMessage());
            },
        ),
        producerInterval: $interval,
    )
    ->run();
