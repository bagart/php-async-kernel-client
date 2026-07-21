<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client\HttpsSocketClient;

/**
 * Immutable configuration for HttpsSocketClient.
 *
 * Defaults enable the HTTP/1.1 connection pool with per-host concurrency
 * control. Disable {@see $keepAlive} to restore the legacy one-shot behaviour
 * (every request opens a fresh TCP+TLS connection).
 */
final class HttpsSocketClientConfig
{
    /**
     * @param  bool    $keepAlive               Master switch for the HTTP/1.1 connection pool. When true the client
     *                                          reuses kept-open sockets across requests to the same host:port.
     *                                          Protocol negotiation is driven by {@see $http2Enabled}, not this flag.
     * @param  bool    $http2Enabled            Whether to advertise h2 in the ALPN string. Independent from
     *                                          {@see $keepAlive} — this is capability negotiation, not lifecycle.
     * @param  int     $maxConnectionsPerHost   Total cap (connecting + active + idle) for a single host:port key.
     *                                          When the cap is reached, new requests are queued until a slot opens.
     * @param  bool    $forceIPv4               Resolve the host via gethostbyname() and connect to the literal IPv4
     *                                          address, skipping AAAA/IPv6 fallback that getaddrinfo() would attempt.
     * @param  bool    $dnsCache                Cache resolved host => ip pairs in-process for {@see $dnsCacheTtl} seconds.
     * @param  float   $dnsCacheTtl             Time-to-live for a DNS cache entry, in seconds.
     * @param  int     $maxIdlePerHost          Maximum idle (kept-open) connections kept per host:port key.
     * @param  int     $maxIdleTotal            Global cap on idle connections across all hosts.
     * @param  float   $idleTimeout             Seconds an idle connection may sit in the pool before it is evicted.
     * @param  ?string $alpnProtos              Explicit ALPN override. When set it wins over {@see $http2Enabled}.
     */
    public function __construct(
        public readonly bool $keepAlive = true,
        public readonly bool $http2Enabled = false,
        public readonly int $maxConnectionsPerHost = 16,
        public readonly bool $forceIPv4 = true,
        public readonly bool $dnsCache = true,
        public readonly float $dnsCacheTtl = 60.0,
        public readonly int $maxIdlePerHost = 4,
        public readonly int $maxIdleTotal = 16,
        public readonly float $idleTimeout = 30.0,
        public readonly ?string $alpnProtos = null,
    ) {
    }

    /**
     * Effective ALPN string: explicit override wins, otherwise based on
     * {@see $http2Enabled} (capability, not lifecycle policy).
     */
    public function effectiveAlpn(): string
    {
        if ($this->alpnProtos !== null) {
            return $this->alpnProtos;
        }

        if ($this->http2Enabled) {
            return 'h2,http/1.1';
        }

        return 'http/1.1';
    }
}
