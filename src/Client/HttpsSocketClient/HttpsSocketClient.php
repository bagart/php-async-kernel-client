<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client\HttpsSocketClient;

use BAGArt\ASKClient\Contracts\Client\NetworkClientContract;
use BAGArt\ASKClient\Contracts\Client\WarmableClientContract;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Exceptions\ASKException;
use BAGArt\AsyncKernel\Exceptions\ASKInterruptException;
use BAGArt\AsyncKernel\Promise\ASKPromise;
use Psr\Http\Message\ResponseInterface;

/**
 * Generic non-blocking TLS socket multiplexer with HTTP/1.x and HTTP/2 support.
 *
 * Two modes:
 *  • keepAlive disabled (default): every request opens a fresh TCP+TLS connection that is
 *    closed as soon as the response arrives. HTTP/2 may be negotiated via ALPN.
 *  • keepAlive enabled (see HttpsSocketClientConfig): ALPN is forced to http/1.1 and completed
 *    connections are returned to a {@see ConnectionPool} keyed by host:port, so subsequent
 *    requests to the same host skip the TCP+TLS handshake entirely.
 */
final class HttpsSocketClient implements NetworkClientContract, WarmableClientContract, ASKTickableContract
{
    private const int DEFAULT_PORT = 443;
    private const float SELECT_TIMEOUT_SEC = 0.05;
    private const string PROTOCOL_HTTP_1_1 = 'http/1.1';
    private const string PROTOCOL_HTTP_2 = 'h2';

    /** @var list<array{request: ASKHttpRequest, promise: ASKPromise, host: string, port: int, key: string}> */
    private array $queue = [];

    /** @var array<string, PooledConnection> */
    private array $activeConnections = [];

    /** @var array<string, ASKPromise> */
    private array $connectionPromises = [];

    /** @var array<string, true> Connections where TLS sent ClientHello and is waiting for server response. */
    private array $tlsWaitForRead = [];

    private readonly ConnectionPool $connectionPool;
    private readonly AsyncDnsResolver $dnsResolver;

    public function __construct(
        private readonly HttpsSocketClientConfig $config = new HttpsSocketClientConfig(),
    ) {
        $this->connectionPool = new ConnectionPool(
            maxIdlePerHost: $this->config->maxIdlePerHost,
            maxIdleTotal: $this->config->maxIdleTotal,
            idleTimeout: $this->config->idleTimeout,
        );

        $this->dnsResolver = new AsyncDnsResolver(
            ttl: $this->config->dnsCacheTtl,
        );
    }

    public function request(ASKHttpRequest $request): ASKPromiseContract
    {
        $host = parse_url($request->url, PHP_URL_HOST);

        if (!$host) {
            throw new ASKException("Cannot extract host from URL: {$request->url}");
        }

        $port = (int)(parse_url($request->url, PHP_URL_PORT) ?? self::DEFAULT_PORT);

        $promise = new ASKPromise(...$this->tickable());


        $this->queue[] = [
            'request' => $request,
            'promise' => $promise,
            'host' => $host,
            'port' => $port,
            'key' => "{$host}:{$port}",
        ];

        return $promise;
    }

    public function tick(int $systemPressure): void
    {
        $this->dnsResolver->tick();
        $this->flushQueue();
        $this->processConnections();

        if ($this->config->keepAlive) {
            $this->connectionPool->evictIdle();
        }
    }

    public function pressure(): int
    {
        $total = count($this->queue) + count($this->activeConnections);

        if ($total === 0) {
            return 0;
        }

        return (int) round(($total / 64) * 100);
    }

    /**
     * Open {@see $count} warm connections to {@see $host}, complete the TLS handshake
     * synchronously, and park them in the pool ready for reuse.
     *
     * Intended for daemons that want to pay the TCP+TLS handshake cost up-front, before
     * the first real request arrives. Blocks until each handshake finishes or the per-
     * connection timeout elapses. Returns the number of connections actually warmed
     * (and now idle in the pool).
     */
    public function warmUp(string $host, int $count, int $port = 443): int
    {
        if (!$this->config->keepAlive || $count <= 0) {
            return 0;
        }

        $key = "{$host}:{$port}";
        $already = $this->connectionPool->idleCountForHost($key);

        $target = min($count, $this->config->maxIdlePerHost) - $already;
        if ($target <= 0) {
            return 0;
        }

        $connectHost = $host;

        if ($this->config->forceIPv4) {
            // warmUp is blocking, resolve DNS synchronously
            $ip = @gethostbyname($host);

            if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
                $connectHost = $ip;
            }
        }

        $warmed = 0;
        for ($i = 0; $i < $target; $i++) {
            try {
                $conn = $this->openConnection(
                    $connectHost,
                    $host,
                    $port,
                    $key,
                    null
                );
            } catch (ASKInterruptException $e) {
                throw $e;
            } catch (\Throwable) {
                continue;
            }

            if ($conn && $this->completeHandshakeBlocking($conn)) {
                $this->connectionPool->release($conn);
                $warmed++;
            }
        }

        return $warmed;
    }

    /**
     * Move as many queued requests as possible into active connections.
     *
     * Returns the number of items dequeued this pass, so {@see tick()} can report
     * real progress even while long downloads keep the active count unchanged.
     *
     * Iterates over a snapshot of queue indices and collects removals, deferring the
     * actual unset until after the loop — mutating the array mid-foreach was shifting
     * PHP's internal pointer and silently skipping neighbouring entries.
     */
    private function flushQueue(): int
    {
        if ($this->queue === []) {
            return 0;
        }

        $dequeued = 0;
        $remove = [];

        // Snapshot keys; we may unset() any of them below without disturbing iteration.
        foreach (array_keys($this->queue) as $i) {
            $pending = $this->queue[$i];

            try {
                $connection = $this->acquireOrCreate($pending);
            } catch (ASKInterruptException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $pending['promise']->reject($e);
                $remove[] = $i;
                continue;
            }

            if ($connection === null) {
                // Fresh non-blocking connect still in progress — retry next tick.
                continue;
            }

            $socketId = $this->socketIdOf($connection);

            $this->activeConnections[$socketId] = $connection;
            $this->connectionPromises[$socketId] = $pending['promise'];

            $remove[] = $i;
            $dequeued++;
        }

        if ($remove !== []) {
            foreach ($remove as $i) {
                unset($this->queue[$i]);
            }
            $this->queue = array_values($this->queue);
        }

        return $dequeued;
    }

    /**
     * Try to reuse an idle pooled connection for the host:port; otherwise open a new one.
     * Returns null when a fresh non-blocking connect is in progress (caller should retry
     * on the next tick).
     */
    private function acquireOrCreate(array $pending): ?PooledConnection
    {
        $key = $pending['key'];

        if ($this->config->keepAlive) {
            $reused = $this->connectionPool->tryAcquire($key);

            if ($reused !== null) {
                if ($this->isSocketAlive($reused)) {
                    $this->prepareHttp1Payload(
                        $reused,
                        $pending['request'],
                        $pending['host'],
                    );

                    return $reused;
                }

                $this->close($reused);
            }
        }

        $ip = $this->resolveHost($pending['host']);

        if ($ip === null) {
            return null;
        }

        return $this->openConnection(
            $ip,
            $pending['host'],
            $pending['port'],
            $key,
            $pending
        );
    }

    /**
     * Select + dispatch one round of socket activity over all in-flight connections.
     *
     * Returns true when stream_select reported activity (readable/writable/exceptional),
     * so {@see tick()} can signal real progress while long downloads keep the connection
     * count constant.
     */
    private function processConnections(): bool
    {
        $read = [];
        $write = [];
        $except = [];
        $socketMap = [];

        // Include DNS resolver UDP sockets so async responses are collected.
        $dnsSockets = $this->dnsResolver->getReadSockets();
        $dnsSocketMap = [];
        foreach ($dnsSockets as $idx => $sock) {
            $rid = (int)$sock;
            $read[] = $sock;
            $except[] = $sock;
            $dnsSocketMap[$rid] = $idx;
        }

        foreach ($this->activeConnections as $id => $conn) {
            if (!is_resource($conn->socket)) {
                $this->failConnection($id, 'invalid socket');
                continue;
            }

            $resourceId = $this->socketIdOf($conn);
            $socketMap[$resourceId] = $id;

            $read[] = $conn->socket;
            $except[] = $conn->socket;

            if ($this->needsWrite($conn) && !isset($this->tlsWaitForRead[$id])) {
                $write[] = $conn->socket;
            }
        }

        if ($read === [] && $write === [] && $except === []) {
            return false;
        }

        $changed = @stream_select(
            $read,
            $write,
            $except,
            0,
            (int)(self::SELECT_TIMEOUT_SEC * 1_000_000),
        );

        if ($changed === false || $changed <= 0) {
            return false;
        }

        // Process DNS responses first — resolved IPs are picked up by flushQueue
        // on the next tick.
        foreach ($read as $socket) {
            $rid = (int)$socket;

            if (isset($dnsSocketMap[$rid])) {
                $this->dnsResolver->processReadable($socket);
            }
        }

        foreach ($write as $socket) {
            $this->writeConnection($socketMap[(int)$socket] ?? null);
        }

        foreach ($read as $socket) {
            $this->readConnection($socketMap[(int)$socket] ?? null);
        }

        foreach ($except as $socket) {
            $this->failConnection($socketMap[(int)$socket] ?? 0, 'socket exception');
        }

        return true;
    }

    private function needsWrite(PooledConnection $conn): bool
    {
        // TLS handshake is the gating step for every connection, pooled or not.
        if (!$conn->tlsReady) {
            return true;
        }

        // Non-pooled connections still need ALPN-driven protocol detection before write.
        if (!$this->config->keepAlive && $conn->protocol === null) {
            return true;
        }

        return $conn->written < strlen($conn->writePayload);
    }

    private function writeConnection(?int $id): void
    {
        if ($id === null || !isset($this->activeConnections[$id])) {
            return;
        }

        $conn = $this->activeConnections[$id];

        // Step 1: complete the TLS handshake for every fresh connection.
        if (!$conn->tlsReady) {
            $tlsResult = $this->finalizeTls($conn);
            if ($tlsResult === null) {
                // The initial ClientHello was sent (or more data was written).
                // Wait for the server to respond before calling finalizeTls again.
                $this->tlsWaitForRead[$id] = true;

                return;
            }
            if ($tlsResult === false) {
                $this->failConnection($id, 'TLS handshake failed');

                return;
            }
        }

        // Step 2 (non-pooled only): detect negotiated ALPN and build the request payload.
        // Pooled connections already have protocol/payload set in prepareH1Payload().
        if (!$this->config->keepAlive && $conn->protocol === null) {
            $this->detectProtocol($id, $conn);

            if ($conn->protocol === null) {
                return;
            }
        }

        $totalLength = strlen($conn->writePayload);
        if ($conn->written >= $totalLength) {
            return;
        }

        $chunk = substr($conn->writePayload, $conn->written);
        $written = @fwrite($conn->socket, $chunk);

        if ($written === false || $written === 0) {
            return;
        }

        $conn->written += $written;
        $conn->lastActivity = microtime(true);
    }

    private function detectProtocol(int $id, PooledConnection $s): void
    {
        // Caller guarantees tlsReady === true; read the alpn ALPN and build payload.
        $meta = stream_get_meta_data($s->socket);
        $alpn = $meta['crypto']['alpn_negotiated'] ?? null;

        $request = $s->request;

        if ($request === null) {
            throw new ASKNetworkException('Request is not set');
        }

        if ($alpn !== null && str_starts_with($alpn, 'h2')) {
            $s->protocol = self::PROTOCOL_HTTP_2;

            $h2 = new Http2Connection();
            $s->processor = $h2;

            $path = $s->path ?: $this->extractPath($request->url);
            $s->writePayload = $h2->getInitialFrames()
                .$h2->buildRequest($request->method, $s->host, $path, $request->body ?? '', $request->headers);
            $s->written = 0;

            return;
        }

        $s->protocol = self::PROTOCOL_HTTP_1_1;
        $s->processor = new Http1Processor();

        $this->prepareHttp1Payload($s, $s->request, $s->host);
    }

    /**
     * Drive a freshly opened connection through TCP-connect + TLS handshake using a
     * bounded blocking select loop. Returns true when the connection is ready for use.
     */
    private function completeHandshakeBlocking(PooledConnection $conn): bool
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            if (!is_resource($conn->socket)) {
                return false;
            }

            $read = [$conn->socket];
            $write = [$conn->socket];
            $except = [$conn->socket];

            $changed = @stream_select($read, $write, $except, 0, 100_000);

            if ($changed === false) {
                return false;
            }

            if ($changed === 0) {
                continue;
            }

            $tlsResult = $this->finalizeTls($conn);

            if ($tlsResult === true) {
                return true;
            }

            if ($tlsResult === false) {
                $this->close($conn);

                return false;
            }
            // null = still negotiating
        }

        $this->close($conn);

        return false;
    }

    /**
     * @param  array{request: ASKHttpRequest, host: string}|null  $pending
     */
    private function openConnection(
        string $connectHost,
        string $requestHost,
        int $port,
        string $key,
        ?array $pending = null,
    ): ?PooledConnection {
        $context = stream_context_create([
            'ssl' => [
                'peer_name' => $requestHost,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'alpn_protos' => $this->config->effectiveAlpn(),
                'allow_self_signed' => false,
            ],
        ]);

        $socket = @stream_socket_client(
            "tcp://{$connectHost}:{$port}",
            $errno,
            $errstr,
            5.0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $context,
        );

        if (!$socket) {
            throw new ASKNetworkException("Socket connect failed: [{$errno}] {$errstr}");
        }

        stream_set_blocking($socket, false);

        $conn = new PooledConnection(
            socket: $socket,
            key: $key,
            host: $requestHost,
            port: $port,
            startedAt: microtime(true),
        );

        if ($pending !== null) {
            // request/path are needed by detectProtocol (non-pooled) and prepareH1Payload (pooled).
            $conn->request = $pending['request'];
            $conn->path = $this->extractPath($pending['request']->url);
        }

        // keepAlive => force HTTP/1.1 pipeline
        if ($this->config->keepAlive) {
            $conn->protocol = self::PROTOCOL_HTTP_1_1;
            $conn->processor = new Http1Processor();

            if ($pending !== null) {
                $this->prepareHttp1Payload($conn, $pending['request'], $pending['host']);
            }
        }

        return $conn;
    }

    /**
     * Build the HTTP/1.x request payload into {@see PooledConnection::$writePayload}.
     * Used both for kept-alive reused connections (no handshake) and the h1 ALPN branch.
     */
    private function prepareHttp1Payload(
        PooledConnection $conn,
        ASKHttpRequest $request,
        string $host,
    ): void {
        $conn->request = $request;
        $conn->path = $this->extractPath($request->url);
        $conn->writePayload = $this->buildHttpRequest(
            method: $request->method,
            host: $host,
            path: $conn->path,
            body: $request->body ?? '',
            headers: $request->headers,
        );
        $conn->written = 0;
    }

    private function readConnection(?int $socketId): void
    {
        if ($socketId === null) {
            return;
        }

        $conn = $this->activeConnections[$socketId] ?? null;

        if ($conn === null) {
            return;
        }

        // The socket may have been closed by a prior step this tick (e.g. releaseOrClose
        // nulled it after delivering the response) or never been a live resource. Guard
        // before fread(): passing null to it is a TypeError, not an eof.
        if ($conn->socket === null || !is_resource($conn->socket)) {
            if (isset($this->connectionPromises[$socketId])) {
                $this->failConnection($socketId, 'Connection closed prematurely by remote peer');
            } else {
                unset($this->activeConnections[$socketId]);
            }

            return;
        }

        // 1. Complete TLS handshake — the socket became readable (server responded).
        if (!$conn->tlsReady) {
            unset($this->tlsWaitForRead[$socketId]);
            $tlsResult = $this->finalizeTls($conn);
            if ($tlsResult === null) {
                // Still negotiating — clear the wait flag so the socket is added to
                // the writable set on the next tick if SSL needs to send more data.
                return;
            }
            if ($tlsResult === false) {
                $this->failConnection($socketId, 'TLS handshake failed');

                return;
            }
            // true → fall through to detectProtocol / read
        }

        // 2. TLS/ALPN handshake phase (non pooled only)
        if (!$this->config->keepAlive && $conn->protocol === null) {
            $this->detectProtocol($socketId, $conn);

            if ($conn->protocol === null) {
                return;
            }
        }

        $chunk = @fread($conn->socket, 8192);

        if ($chunk === false) {
            $this->failConnection($socketId, 'Socket read error');

            return;
        }

        if ($chunk === '') {
            $meta = stream_get_meta_data($conn->socket);
            if ($meta['eof'] ?? false) {
                // If the response promise is already gone (e.g. resolved on an earlier
                // chunk for a kept-alive connection), the remote FIN must just retire
                // this idle socket silently — failConnection() would reject twice.
                if (isset($this->connectionPromises[$socketId])) {
                    $this->failConnection($socketId, 'Connection closed prematurely by remote peer');
                } else {
                    $this->close($conn);
                    unset($this->activeConnections[$socketId]);
                }
            }

            return;
        }

        $conn->readBuffer .= $chunk;
        $conn->lastActivity = microtime(true);

        if ($conn->processor === null) {
            return;
        }

        try {
            $response = $conn->processor->handleBuffer($conn->readBuffer);
        } catch (ASKInterruptException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->failConnection($socketId, $e->getMessage());

            return;
        }

        // Flush protocol-level control frames the processor queued while parsing
        // (HTTP/2 SETTINGS ACK, WINDOW_UPDATE, PING ACK). Safe no-op for HTTP/1.x.
        $outbound = $conn->processor->drainOutbound();
        if ($outbound !== '' && $conn->socket !== null && is_resource($conn->socket)) {
            @fwrite($conn->socket, $outbound);
        }

        if ($response === null) {
            return;
        }

        // Detach the bookkeeping BEFORE resolving so the cleanup below is unconditional.
        // The previous version only unset activeConnections/connectionPromises inside the
        // `if ($promise !== null)` block — if $promise ever came back null the socket
        // leaked into activeConnections forever, polling a dead resource each tick.
        $promise = $this->connectionPromises[$socketId] ?? null;
        unset(
            $this->activeConnections[$socketId],
            $this->connectionPromises[$socketId],
        );

        $this->releaseOrClose($conn, $response);

        if ($promise !== null) {
            try {
                $promise->resolve($response);
            } catch (ASKInterruptException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $promise->reject($e);
            }
        }
    }

    /**
     * Decide what to do with a connection once its response has been delivered:
     *  • keep-alive + server did not signal close → return to pool for reuse;
     *  • otherwise → close the socket.
     */
    private function releaseOrClose(PooledConnection $conn, ResponseInterface $response): void
    {
        if (!$this->config->keepAlive) {
            $this->close($conn);
            $conn->socket = null;

            return;
        }

        if ($conn->socket === null || !is_resource($conn->socket)) {
            $conn->socket = null;
            return;
        }

        $connectionHeader = strtolower($response->getHeaderLine('Connection'));

        $http1 = $response->getProtocolVersion() === '1.0';

        $serverForcesClose =
            $connectionHeader === 'close'
            || ($http1 && $connectionHeader !== 'keep-alive');

        if ($serverForcesClose) {
            $this->close($conn);
            $conn->socket = null;
            return;
        }

        $meta = @stream_get_meta_data($conn->socket);
        $isAlive = is_resource($conn->socket) && !($meta['eof'] ?? false);

        if (!$isAlive) {
            $this->close($conn);
            $conn->socket = null;

            return;
        }

        $this->connectionPool->release($conn);
    }

    private function buildHttpRequest(
        string $method,
        string $host,
        string $path,
        string $body,
        array $headers = [],
    ): string {
        $lines = "{$method} {$path} HTTP/1.1\r\n"
            ."Host: {$host}\r\n";

        foreach ($headers as $name => $value) {
            $lines .= "{$name}: {$value}\r\n";
        }

        if (!isset($headers['User-Agent'])) {
            $lines .= "User-Agent: HttpsSocketClient/1.0\r\n";
        }

        if (!isset($headers['Accept'])) {
            $lines .= "Accept: application/json\r\n";
        }

        if ($body !== '' && !isset($headers['Content-Type'])) {
            $lines .= "Content-Type: application/json\r\n";
        }

        if (!isset($headers['Content-Length'])) {
            $lines .= "Content-Length: ".\strlen($body)."\r\n";
        }

        // keep-alive when pooling is on, close otherwise — preserves legacy behaviour.
        $connectionDirective = $this->config->keepAlive ? 'keep-alive' : 'close';
        $lines .= "Connection: {$connectionDirective}\r\n\r\n".$body;

        return $lines;
    }

    private function failConnection(int $socketId, string $reason): void
    {
        $promise = $this->connectionPromises[$socketId] ?? null;
        $conn = $this->activeConnections[$socketId] ?? null;

        unset(
            $this->activeConnections[$socketId],
            $this->connectionPromises[$socketId],
            $this->tlsWaitForRead[$socketId],
        );

        if ($conn) {
            $this->close($conn);
        }

        $promise?->reject(new ASKNetworkException($reason));
    }

    private function close(PooledConnection $conn): void
    {
        if (is_resource($conn->socket)) {
            @fclose($conn->socket);
        }
        $conn->socket = null;
    }

    /**
     * Stable integer key for a connection's underlying socket resource.
     *
     * Centralised so the (int) cast lives in exactly one place; used both to index
     * {@see $activeConnections}/{@see $connectionPromises} and to map stream_select
     * results back to those keys.
     */
    private function socketIdOf(PooledConnection $conn): int
    {
        return (int)$conn->socket;
    }

    /**
     * A resource is only worth selecting on while it is both a live resource and not
     * yet flagged eof by the stream layer. Used to validate pooled connections before
     * reuse and to decide whether a closed-by-peer socket can be retired silently.
     */
    private function isSocketAlive(PooledConnection $conn): bool
    {
        if ($conn->socket === null || !is_resource($conn->socket)) {
            return false;
        }

        $meta = @stream_get_meta_data($conn->socket);

        return !($meta['eof'] ?? false);
    }

    private function finalizeTls(PooledConnection $conn): ?bool
    {
        if ($conn->tlsReady) {
            return true;
        }

        if (!is_resource($conn->socket)) {
            return false;
        }

        $result = @stream_socket_enable_crypto(
            $conn->socket,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
        );

        if ($result === true) {
            $conn->tlsReady = true;

            return true;
        }

        if ($result === false) {
            return false;
        }

        return null;
    }

    private function resolveHost(string $host): ?string
    {
        if (!$this->config->forceIPv4) {
            return $host;
        }

        return $this->dnsResolver->resolve($host);
    }

    public function idlePoolSize(): int
    {
        return $this->connectionPool->idleCount();
    }

    public function drain(): void
    {
        while (!$this->isIdle()) {
            $this->tick(0);
        }
    }

    public function isIdle(): bool
    {
        return $this->queue === [] && $this->activeConnections === [];
    }

    public function queueSize(): int
    {
        return count($this->queue) + count($this->activeConnections);
    }

    private function extractPath(string $url): string
    {
        $parsed = parse_url($url);

        return ($parsed['path'] ?? '/')
            .(isset($parsed['query']) ? "?{$parsed['query']}" : '');
    }

    public function tickable(): array
    {
        return [$this];
    }

    public function __destruct()
    {
        $this->connectionPool->closeAll();
    }
}
