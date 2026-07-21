<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns\Adapters;

use BAGArt\ASKClient\Client\HttpsSocketClient\AsyncDnsResolver;
use BAGArt\ASKClient\Dns\AskDnsAdapterContract;
use BAGArt\ASKClient\Dns\AskDnsConfig;

final class AsyncKernelDnsAdapter implements AskDnsAdapterContract
{
    public const string TYPE = 'ask-kernel';
    public const bool TLS_SUPPORTED = true;

    private readonly AsyncDnsResolver $resolver;

    public function __construct(
        private readonly AskDnsConfig $config,
    ) {
        $this->resolver = new AsyncDnsResolver(
            ttl: $this->config->ttl(),
            failureTtl: $this->config->failureTtl(),
            dnsServers: $this->config->dnsServers(),
            useTls: $this->config->useTls(),
        );
    }

    public function resolve(string $host): ?string
    {
        return $this->resolver->resolve($host);
    }

    public function tick(): void
    {
        $this->resolver->tick();
    }

    public function getReadSockets(): array
    {
        return $this->resolver->getReadSockets();
    }

    public function processReadable(mixed $socket): bool
    {
        return $this->resolver->processReadable($socket);
    }

    public function flushFresh(): array
    {
        return $this->resolver->flushFresh();
    }

    public static function clearCache(): void
    {
        AsyncDnsResolver::clearCache();
    }

    public function warmUp(string $host): ?string
    {
        $ip = $this->resolver->resolve($host);
        if ($ip !== null) {
            return $ip;
        }

        $deadline = microtime(true) + $this->config->timeout();
        while (microtime(true) < $deadline) {
            $this->resolver->tick();
            $sockets = $this->resolver->getReadSockets();
            if ($sockets !== []) {
                $read = $sockets;
                $write = null;
                $except = null;
                if (@stream_select($read, $write, $except, 0, 100000) > 0) {
                    foreach ($read as $socket) {
                        $this->resolver->processReadable($socket);
                    }
                }
            }
            $fresh = $this->resolver->flushFresh();
            if (isset($fresh[$host])) {
                return $fresh[$host];
            }
        }

        return null;
    }
}
