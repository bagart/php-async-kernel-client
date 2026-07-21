<?php

declare(strict_types=1);

use BAGArt\ASKClient\Dns\AskDnsConfig;
use BAGArt\ASKClient\Dns\AskDnsRegistry;
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
    'dns-adapter::',
    'dns-servers::',
    'dns-timeout::',
    'dns-use-tls::',
    'help',
];

$options = CliActions::parseOptions(
    getopt('', $definedOptions),
    $definedOptions
);

CliActions::initRuntime($options);

if (isset($options['help'])) {
    $adapters = implode(', ', AskDnsRegistry::build()->types());
    echo "Usage:
php commands/ask_dns.php                              # Default: resolve every 1s
php commands/ask_dns.php --interval=5                 # Resolve every 5s
php commands/ask_dns.php --dns-adapter=ask-kernel     # Use a specific DNS adapter

Options:
  --interval=N                        Seconds between DNS lookups (default: 1)
  --memory-limit=512M                 PHP memory limit (default: 512M)
  --log-level=debug|info|warning|error  minimum log level (default: info)
  --dns-adapter=<type>                DNS adapter: {$adapters} (default: ask-kernel)
  --dns-servers=<ip,...>              DNS server IPs (default: 8.8.8.8,1.1.1.1)
  --dns-timeout=N                     DNS query timeout in seconds (default: 3.0)
  --dns-use-tls=<bool>                Use DNS over TLS (default: false)
  --help
";
    exit(0);
}

$interval = (int)($options['interval'] ?? 1);
$logLevel = (string)($options['log-level'] ?? null) ?: ASKLogWrapper::LEVEL_DEFAULT;

// Pick DNS adapter
$registry = AskDnsRegistry::build();
$dnsAdapterType = (string)($options['dns-adapter'] ?? getenv('ASK_DNS_ADAPTER') ?: '');
if ($dnsAdapterType === '' || !$registry->has($dnsAdapterType)) {
    $dnsAdapterType = AskDnsRegistry::DEFAULT_ADAPTER;
}

$dnsConfig = AskDnsConfig::fromOptions($options);
$adapter = $registry->make($dnsAdapterType, $dnsConfig);

$sources = require __DIR__.'/includes/dns-sources.php';

$kernelLogger = new ASKLogWrapper(minLevel: $logLevel);

$kernel = new AsyncKernel($kernelLogger);

$kernel
    ->addDaemon(
        daemon: new ASKFnDaemon(
            daemonContext: new ASKFnDaemonContext(
                daemonName: 'ask-dns',
                logger: $kernelLogger,
            ),
            fnProduce: function (ASKFnDaemonContext $context) use ($sources, $adapter): void {
                $source = $sources[array_rand($sources)];
                $host = $source['host'];

                $ip = $adapter->resolve($host);

                if ($ip !== null) {
                    echo date('H:i:s').' '
                        .str_pad("[{$source['name']}]", 40)
                        ." {$host} -> {$ip}\n";

                    return;
                }

                $globalDeadline = microtime(true) + 5.0;

                while (microtime(true) < $globalDeadline) {
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

                    if (isset($fresh[$host])) {
                        echo date('H:i:s').' '
                            .str_pad("[{$source['name']}]", 40)
                            ." {$host} -> {$fresh[$host]}\n";

                        return;
                    }

                    if ($fresh !== []) {
                        foreach ($fresh as $resolvedHost => $resolvedIp) {
                            $adapter->warmUp($resolvedHost);
                        }
                    }

                    usleep(10_000);
                }

                echo date('H:i:s').' '
                    .str_pad("[{$source['name']}]", 40)
                    ." {$host} -> TIMEOUT\n";
            },
            fnCanProduce: fn (ASKFnDaemonContext $context): bool => true,
            fnStartup: function (ASKFnDaemonContext $context) use ($dnsAdapterType): void {
                $context->logger->info("{$context->daemonName} started (adapter: {$dnsAdapterType}).");
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
