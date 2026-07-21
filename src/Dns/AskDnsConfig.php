<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns;

final readonly class AskDnsConfig
{
    public function __construct(
        private array $dnsServers = ['8.8.8.8', '1.1.1.1'],
        private float $timeout = 3.0,
        private float $ttl = 60.0,
        private float $failureTtl = 10.0,
        private bool $useTls = true,
        private bool $forceIPv4 = true,
        private array $warmUpHosts = [],
    ) {
    }

    public static function fromEnv(): self
    {
        $dnsServers = getenv('ASK_DNS_SERVERS');
        $useTlsEnv = getenv('ASK_DNS_USE_TLS');
        return new self(
            dnsServers: $dnsServers !== false ? array_map('trim', explode(',', $dnsServers)) : ['8.8.8.8', '1.1.1.1'],
            timeout: (float)(getenv('ASK_DNS_TIMEOUT') ?: 3.0),
            ttl: (float)(getenv('ASK_DNS_TTL') ?: 60.0),
            failureTtl: (float)(getenv('ASK_DNS_FAILURE_TTL') ?: 10.0),
            useTls: $useTlsEnv !== false ? filter_var($useTlsEnv, FILTER_VALIDATE_BOOLEAN) : true,
            forceIPv4: (bool)(getenv('ASK_DNS_FORCE_IPV4') ?: true),
            warmUpHosts: getenv('ASK_DNS_WARMUP') !== false ? array_map('trim', explode(',', (string)getenv('ASK_DNS_WARMUP'))) : [],
        );
    }

    public static function fromArray(array $data): self
    {
        return new self(
            dnsServers: (array)($data['dns_servers'] ?? ['8.8.8.8', '1.1.1.1']),
            timeout: (float)($data['timeout'] ?? 3.0),
            ttl: (float)($data['ttl'] ?? 60.0),
            failureTtl: (float)($data['failure_ttl'] ?? 10.0),
            useTls: (bool)($data['use_tls'] ?? true),
            forceIPv4: (bool)($data['force_ipv4'] ?? true),
            warmUpHosts: (array)($data['warm_up_hosts'] ?? []),
        );
    }

    public static function fromOptions(array $options): self
    {
        $dnsServers = $options['dns-servers'] ?? getenv('ASK_DNS_SERVERS');
        $useTlsOpt = $options['dns-use-tls'] ?? getenv('ASK_DNS_USE_TLS');
        return new self(
            dnsServers: $dnsServers !== false && $dnsServers !== null ? (is_array($dnsServers) ? $dnsServers : array_map('trim', explode(',', (string)$dnsServers))) : ['8.8.8.8', '1.1.1.1'],
            timeout: (float)($options['dns-timeout'] ?? getenv('ASK_DNS_TIMEOUT') ?: 3.0),
            ttl: (float)($options['dns-ttl'] ?? getenv('ASK_DNS_TTL') ?: 60.0),
            failureTtl: (float)($options['dns-failure-ttl'] ?? getenv('ASK_DNS_FAILURE_TTL') ?: 10.0),
            useTls: $useTlsOpt !== false && $useTlsOpt !== null ? filter_var($useTlsOpt, FILTER_VALIDATE_BOOLEAN) : true,
            forceIPv4: (bool)($options['dns-force-ipv4'] ?? getenv('ASK_DNS_FORCE_IPV4') ?: true),
            warmUpHosts: (array)($options['dns-warmup'] ?? []),
        );
    }

    public function toArray(): array
    {
        return [
            'dns_servers' => $this->dnsServers,
            'timeout' => $this->timeout,
            'ttl' => $this->ttl,
            'failure_ttl' => $this->failureTtl,
            'use_tls' => $this->useTls,
            'force_ipv4' => $this->forceIPv4,
            'warm_up_hosts' => $this->warmUpHosts,
        ];
    }

    public function dnsServers(): array
    {
        return $this->dnsServers;
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    public function ttl(): float
    {
        return $this->ttl;
    }

    public function failureTtl(): float
    {
        return $this->failureTtl;
    }

    public function useTls(): bool
    {
        return $this->useTls;
    }

    public function forceIPv4(): bool
    {
        return $this->forceIPv4;
    }

    public function warmUpHosts(): array
    {
        return $this->warmUpHosts;
    }
}
