<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client\HttpsSocketClient;

use BAGArt\ASKClient\Contracts\Client\ProtocolProcessorContract;
use BAGArt\ASKClient\Request\ASKHttpRequest;

/**
 * State of a single kept-open TCP+TLS connection that can be reused across requests.
 *
 * One PooledConnection is bound to exactly one in-flight request at a time (HTTP/1.1
 * has no multiplexing). After {@see $processor} reports a complete response the
 * connection returns to the pool for the next request to the same host:port.
 *
 * @internal Not part of the public client API; surfaced only for the pool implementation.
 */
final class PooledConnection
{
    /** @var resource|null */
    public $socket;

    /** Fully resolved "host:port" key this connection belongs to. */
    public readonly string $key;

    public readonly string $host;
    public readonly int $port;

    /** Negotiated ALPN protocol; null until detected, "http/1.1" while pooling is active. */
    public ?string $protocol = null;

    /** Processor reused across responses on this connection; null until detected. */
    public ?ProtocolProcessorContract $processor = null;

    /** True once the TLS handshake has completed. */
    public bool $tlsReady = false;

    /** Leftover bytes from the previous response, seeding the next one. */
    public string $readBuffer = '';

    /** Encoded request payload for the in-flight (or next) request. */
    public string $writePayload = '';

    /** Bytes of {@see $writePayload} already sent. */
    public int $written = 0;

    /** Request this connection currently serves, null when idle. */
    public ?ASKHttpRequest $request = null;

    /** Path component of {@see $request} url, cached during payload build. */
    public string $path = '';

    /** microtime(true) of the last read/write activity, used for idle eviction. */
    public float $lastActivity;

    /** Timestamp when this connection was created (microtime(true)). */
    public readonly float $createdAt;

    /** Number of requests that have been served over this connection. */
    public int $useCount = 0;

    /**
     * @param resource     $socket
     */
    public function __construct(
        $socket,
        string $key,
        string $host,
        int $port,
        float $startedAt,
    ) {
        $this->socket = $socket;
        $this->key = $key;
        $this->host = $host;
        $this->port = $port;
        $this->lastActivity = $startedAt;
        $this->createdAt = $startedAt;
    }

    /**
     * Reset per-request write/processing state so the connection is ready to send
     * a brand-new request while keeping TLS + processor state intact.
     */
    public function resetForReuse(): void
    {
        $this->writePayload = '';
        $this->written = 0;
        $this->readBuffer = '';
        $this->request = null;
        $this->path = '';

        //reset of tlsReady/processor/protocol will crash reuse semantics
    }
}
