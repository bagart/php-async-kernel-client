<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns\Adapters;

use BAGArt\ASKClient\Dns\AskDnsAdapterContract;
use BAGArt\ASKClient\Dns\AskDnsConfig;
use BAGArt\ASKClient\Exceptions\AskConfigException;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\StreamSelectDriver;

final class AmpDnsAdapter implements AskDnsAdapterContract
{
    public const string TYPE = 'amphp';

    /** @var array<string, array{ip: string, expiresAt: float}> */
    private static array $globalCache = [];

    /** @var array<string, array{expiresAt: float}> */
    private static array $failureCache = [];

    /** @var array<string, true> */
    private array $pending = [];

    /** @var array<string, string> */
    private array $fresh = [];

    private readonly float $ttl;
    private readonly float $failureTtl;
    private readonly float $timeout;

    private ?\Amp\Dns\DnsResolver $ampResolver = null;

    public function __construct(
        private readonly AskDnsConfig $config,
    ) {
        if (!interface_exists(\Amp\Dns\DnsResolver::class)) {
            throw new AskConfigException('amphp/dns is not installed. Run: composer require --dev amphp/dns');
        }

        $this->ttl = $this->config->ttl();
        $this->failureTtl = $this->config->failureTtl();
        $this->timeout = $this->config->timeout();
    }

    public function resolve(string $host): ?string
    {
        $entry = self::$globalCache[$host] ?? null;
        if ($entry !== null && $entry['expiresAt'] > microtime(true)) {
            return $entry['ip'];
        }

        $failed = self::$failureCache[$host] ?? null;
        if ($failed !== null && $failed['expiresAt'] > microtime(true)) {
            return null;
        }

        if (isset($this->fresh[$host])) {
            return $this->fresh[$host];
        }

        if (isset($this->pending[$host])) {
            return null;
        }

        $this->pending[$host] = true;

        return null;
    }

    public function tick(): void
    {
        if ($this->pending === []) {
            return;
        }

        $this->resolvePending();
    }

    public function getReadSockets(): array
    {
        return [];
    }

    public function processReadable(mixed $socket): bool
    {
        return false;
    }

    public function flushFresh(): array
    {
        $result = $this->fresh;
        $this->fresh = [];

        return $result;
    }

    public static function clearCache(): void
    {
        self::$globalCache = [];
        self::$failureCache = [];
    }

    public function warmUp(string $host): ?string
    {
        $ip = $this->resolve($host);
        if ($ip !== null) {
            return $ip;
        }

        // Attempt to bootstrap host resolution
        $this->resolvePending();

        return $this->fresh[$host] ?? null;
    }

    private function resolver(): \Amp\Dns\DnsResolver
    {
        if ($this->ampResolver === null) {
            $config = new \Amp\Dns\DnsConfig(
                nameservers: $this->config->dnsServers(),
            );
            $this->ampResolver = \Amp\Dns\createDefaultResolver($config);
        }

        return $this->ampResolver;
    }

    private function resolvePending(): void
    {
        $hosts = array_keys($this->pending);
        $this->pending = [];

        if ($hosts === []) {
            return;
        }

        $driver = new StreamSelectDriver();
        $previous = EventLoop::getDriver();
        EventLoop::setDriver($driver);

        try {
            $remaining = count($hosts);
            $results = [];
            $errors = [];
            $deadline = microtime(true) + $this->timeout;
            $driverStarted = false;

            foreach ($hosts as $host) {
                \Amp\async(function () use ($host, &$results, &$errors, &$remaining, $driver): void {
                    try {
                        $records = \Amp\Dns\resolve($host, \Amp\Dns\DnsRecord::A);
                        $ip = $records[0]->getValue();
                        if (filter_var($ip, FILTER_VALIDATE_IP)) {
                            $results[$host] = $ip;
                        } else {
                            $errors[$host] = 'Invalid IP';
                        }
                    } catch (\Throwable $e) {
                        $errors[$host] = $e->getMessage();
                    }

                    $remaining--;
                    if ($remaining <= 0) {
                        $driver->stop();
                    }
                });
            }

            $timeoutId = $driver->delay($this->timeout, function () use ($driver): void {
                $driver->stop();
            });

            $driver->run();

            $driver->cancel($timeoutId);

            $now = microtime(true);
            foreach ($results as $host => $ip) {
                self::$globalCache[$host] = [
                    'ip' => $ip,
                    'expiresAt' => $now + $this->ttl,
                ];
                $this->fresh[$host] = $ip;
            }

            foreach ($errors as $host => $error) {
                self::$failureCache[$host] = [
                    'expiresAt' => $now + $this->failureTtl,
                ];
            }
        } finally {
            EventLoop::setDriver($previous);
        }
    }
}
