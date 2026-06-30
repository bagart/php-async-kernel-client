<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client\HttpsSocketClient;

/**
 * Immutable configuration for HttpsSocketClient.
 *
 * All defaults preserve the legacy one-shot behaviour: every request opens a
 * fresh TCP+TLS connection that is closed as soon as the response arrives.
 * Enable {@see $keepAlive} to activate the HTTP/1.1 connection pool.
 */
final class HttpsSocketClientConfig
{
    /**
     * @param  bool    $keepAlive        Master switch for the HTTP/1.1 connection pool. When true the client
     *                                   forces ALPN to http/1.1 (no h2) and reuses kept-open sockets across
     *                                   requests to the same host:port.
     * @param  bool    $forceIPv4        Resolve the host via gethostbyname() and connect to the literal IPv4
     *                                   address, skipping AAAA/IPv6 fallback that getaddrinfo() would attempt.
     * @param  bool    $dnsCache         Cache resolved host => ip pairs in-process for {@see $dnsCacheTtl} seconds.
     * @param  float   $dnsCacheTtl      Time-to-live for a DNS cache entry, in seconds.
     * @param  int     $maxIdlePerHost   Maximum idle (kept-open) connections kept per host:port key.
     * @param  int     $maxIdleTotal     Global cap on idle connections across all hosts.
     * @param  float   $idleTimeout      Seconds an idle connection may sit in the pool before it is evicted.
     * @param  ?string $alpnProtos       ALPN string offered during TLS handshake. null = auto: "http/1.1" when
     *                                   keepAlive is on, "h2,http/1.1" otherwise.
     */
    public function __construct(
        public readonly bool $keepAlive = false,
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
     * Effective ALPN string: explicit override wins, otherwise "http/1.1".
     *
     * HTTP/2 is only beneficial when streams can be multiplexed over a
     * persistent connection. Without keep-alive every request opens a fresh
     * connection, so negotiating h2 just adds framing overhead with zero gain.
     * Callers that want h2 on one-shot connections can set {@see $alpnProtos}
     * explicitly.
     */
    public function effectiveAlpn(): string
    {
        return $this->alpnProtos ?? 'http/1.1';
    }
}
