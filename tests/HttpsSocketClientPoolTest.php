<?php

declare(strict_types=1);

use BAGArt\ASKClient\Client\HttpsSocketClient\ConnectionPool;
use BAGArt\ASKClient\Client\HttpsSocketClient\HttpsSocketClient;
use BAGArt\ASKClient\Client\HttpsSocketClient\HttpsSocketClientConfig;
use BAGArt\ASKClient\Client\HttpsSocketClient\PooledConnection;
use BAGArt\ASKClient\Request\ASKHttpRequest;

/**
 * Helper: build a PooledConnection wrapping a live duplex TCP socket pair (loopback).
 * Uses STREAM_PF_INET to stay portable across Windows and POSIX, since stream_socket_pair
 * with STREAM_PF_UNIX is unsupported on Windows.
 */
function makePooledConnection(string $key = 'example.com:443', string $host = 'example.com'): PooledConnection
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$server) {
        throw new RuntimeException("stream_socket_server failed: [{$errno}] {$errstr}");
    }

    $port = (int) substr(stream_socket_get_name($server, false), strrpos(stream_socket_get_name($server, false), ':') + 1);
    $client = stream_socket_client("tcp://127.0.0.1:{$port}");
    $accepted = @stream_socket_accept($server, 1);
    fclose($server);
    if ($accepted !== false) {
        fclose($accepted);
    }

    $conn = new PooledConnection(
        socket: $client,
        key: $key,
        host: $host,
        port: 443,
        startedAt: microtime(true),
    );
    $conn->protocol = 'http/1.1';

    return $conn;
}

describe('ConnectionPool', function () {
    it('releases and acquires a connection for the same key', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 4, maxIdleTotal: 16, idleTimeout: 30.0);
        expect($pool->idleCount())->toBe(0);

        $conn = makePooledConnection('api.telegram.org:443');
        $pool->release($conn);

        expect($pool->idleCount())->toBe(1);
        expect($pool->idleCountForHost('api.telegram.org:443'))->toBe(1);

        $reused = $pool->tryAcquire('api.telegram.org:443');

        expect($reused)->not->toBeNull()
            ->and($reused)->toBe($conn)
            ->and($pool->idleCount())->toBe(0);
    });

    it('returns null when no idle connection exists for the key', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 4, maxIdleTotal: 16, idleTimeout: 30.0);

        expect($pool->tryAcquire('unknown.host:443'))->toBeNull();
    });

    it('drops connections beyond the per-host cap', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 2, maxIdleTotal: 16, idleTimeout: 30.0);

        $kept = makePooledConnection();
        $overflow = makePooledConnection();

        $pool->release($kept);
        $pool->release($overflow);

        expect($pool->idleCount())->toBe(2);

        $third = makePooledConnection();
        $pool->release($third);

        // Cap is 2 — third release must drop the connection instead of storing it.
        expect($pool->idleCount())->toBe(2)
            ->and($third->socket)->toBeNull();
    });

    it('enforces the global idle total across hosts', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 10, maxIdleTotal: 2, idleTimeout: 30.0);

        $pool->release(makePooledConnection('a.host:443'));
        $pool->release(makePooledConnection('b.host:443'));

        $over = makePooledConnection('c.host:443');
        $pool->release($over);

        expect($pool->idleCount())->toBe(2)
            ->and($over->socket)->toBeNull();
    });

    it('evicts idle connections past the timeout', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 4, maxIdleTotal: 16, idleTimeout: 0.05);

        $pool->release(makePooledConnection());

        expect($pool->idleCount())->toBe(1);

        usleep(60_000); // > 50ms timeout
        $evicted = $pool->evictIdle();

        expect($evicted)->toBe(1)
            ->and($pool->idleCount())->toBe(0);
    });

    it('does not evict connections still within the timeout', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 4, maxIdleTotal: 16, idleTimeout: 10.0);

        $pool->release(makePooledConnection());
        $evicted = $pool->evictIdle();

        expect($evicted)->toBe(0)
            ->and($pool->idleCount())->toBe(1);
    });

    it('resets per-request state on acquire for reuse', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 4, maxIdleTotal: 16, idleTimeout: 30.0);

        $conn = makePooledConnection();
        $conn->writePayload = 'stale payload';
        $conn->written = 42;
        $conn->readBuffer = 'leftover';

        $pool->release($conn);
        $reused = $pool->tryAcquire($conn->key);

        expect($reused->writePayload)->toBe('')
            ->and($reused->written)->toBe(0)
            ->and($reused->readBuffer)->toBe('');
    });

    it('drops a connection whose socket was already closed', function () {
        $pool = new ConnectionPool(maxIdlePerHost: 4, maxIdleTotal: 16, idleTimeout: 30.0);

        $dead = makePooledConnection();
        fclose($dead->socket);
        $dead->socket = null;

        $pool->release($dead);

        expect($pool->idleCount())->toBe(0);
    });
});

describe('HttpsSocketClientConfig', function () {
    it('defaults to connection pooling enabled, http/1.1 ALPN', function () {
        $cfg = new HttpsSocketClientConfig();

        expect($cfg->keepAlive)->toBeTrue()
            ->and($cfg->http2Enabled)->toBeFalse()
            ->and($cfg->effectiveAlpn())->toBe('http/1.1');
    });

    it('advertises h2 when http2Enabled is true', function () {
        $cfg = new HttpsSocketClientConfig(http2Enabled: true);

        expect($cfg->effectiveAlpn())->toBe('h2,http/1.1');
    });

    it('honours an explicit alpn override over http2Enabled', function () {
        $cfg = new HttpsSocketClientConfig(http2Enabled: true, alpnProtos: 'h2');

        expect($cfg->effectiveAlpn())->toBe('h2');
    });

    it('separates ALPN from keepAlive — http2Enabled controls capability', function () {
        $withoutH2 = new HttpsSocketClientConfig(keepAlive: false, http2Enabled: false);
        $withH2 = new HttpsSocketClientConfig(keepAlive: false, http2Enabled: true);

        expect($withoutH2->effectiveAlpn())->toBe('http/1.1')
            ->and($withH2->effectiveAlpn())->toBe('h2,http/1.1');
    });

    it('enforces a per-host connection cap by default', function () {
        $cfg = new HttpsSocketClientConfig();

        expect($cfg->maxConnectionsPerHost)->toBe(16);
    });
});

/*
 * White-box unit tests for the tick/flush/eof machinery — no real network.
 *
 * They cover the regressions that motivated the refactor:
 *   • tick() reports work via activity, not via shrinking queue/active counts;
 *   • flushQueue() never drops queued entries while iterating;
 *   • a remote FIN on an already-resolved (idle) connection retires the socket
 *     silently instead of rejecting the promise a second time.
 */
describe('HttpsSocketClient tick/flush/eof', function () {
    it('reports no work when idle', function () {
        $client = new HttpsSocketClient(new HttpsSocketClientConfig());

        $client->tick(0);
        expect($client->isIdle())->toBeTrue();
    });

    it('implements WarmableClientContract', function () {
        expect(is_a(
            HttpsSocketClient::class,
            \BAGArt\ASKClient\Contracts\Client\WarmableClientContract::class,
            true,
        ))->toBeTrue();
    });

    it('warmUp is a no-op when keepAlive is disabled', function () {
        // warmUp() is a no-op without keepAlive, and must report 0 warmed — and crucially
        // must NOT throw (this is the regression for the property-declaration bug).
        $client = new HttpsSocketClient(new HttpsSocketClientConfig(keepAlive: false));

        expect($client->warmUp('localhost', 2))->toBe(0);
    });

    it('exposes a MetricsCollector instance', function () {
        $client = new HttpsSocketClient(new HttpsSocketClientConfig(keepAlive: false));

        expect($client->metrics())->toBeInstanceOf(
            \BAGArt\ASKClient\Client\ConnectionManager\MetricsCollector::class,
        );
    });

    it('returns 0 from flushQueue when the queue is empty', function () {
        $client = new HttpsSocketClient(new HttpsSocketClientConfig());

        $rc = new ReflectionObject($client);
        $flushQueue = $rc->getMethod('flushQueue');

        // No queued requests → flushQueue performs no work and reports zero dequeued,
        // which is what tick() relies on to avoid false "work done" signals.
        expect($flushQueue->invoke($client))->toBe(0);
    });

    it('drains every queued entry in a single flushQueue pass', function () {
        // Regression guard for the mid-foreach unset() bug: when flushQueue() removed
        // entries while iterating, PHP's internal pointer could skip a neighbour, leaving
        // it orphaned in the queue. We assert every entry is visited in ONE pass by
        // checking the queue array is fully empty afterwards — no survivor means no skip.
        //
        // The error handler is silenced only for this test because stream_socket_client
        // to a non-resolvable host emits a PHP warning that Pest flags as risky; the
        // invariant under test (queue fully drained) does not depend on that output.
        $client = new HttpsSocketClient(new HttpsSocketClientConfig(forceIPv4: false));

        for ($i = 0; $i < 3; $i++) {
            $client->request(new ASKHttpRequest(
                url: 'https://nonexistent.invalid/'.$i,
                method: 'GET',
            ));
        }

        expect($client->queueSize())->toBe(3);

        $rc = new ReflectionObject($client);
        $flushQueue = $rc->getMethod('flushQueue');

        set_error_handler(static fn () => true);

        try {
            $flushQueue->invoke($client);
        } finally {
            restore_error_handler();
        }

        $queue = $rc->getProperty('queue');

        // Every entry was visited this pass: whether it connected (moved to
        // activeConnections) or was rejected (promise rejected + removed), it must NOT
        // still sit in the queue — that would mean the mid-foreach unset() skipped it.
        expect(count($queue->getValue($client)))->toBe(0);
    });

    it('retires an eof socket silently when its promise is already gone', function () {
        // Simulate a kept-alive connection whose response was already delivered (so the
        // promise has been unset) and whose peer then sent FIN. readConnection() must NOT
        // call reject() again — it should just drop the socket from activeConnections.
        $client = new HttpsSocketClient(new HttpsSocketClientConfig(keepAlive: true));

        // Build the connection directly with a null socket — this is exactly the state
        // left behind after releaseOrClose() nulled it, before the remote FIN is noticed.
        $conn = new PooledConnection(
            socket: null,
            key: 'peer.closed:443',
            host: 'peer.closed',
            port: 443,
            startedAt: microtime(true),
        );
        $conn->protocol = 'http/1.1';
        $conn->tlsReady = true;
        $conn->processor = new \BAGArt\ASKClient\Client\HttpsSocketClient\Http1Processor();

        $rc = new ReflectionObject($client);
        $active = $rc->getProperty('activeConnections');
        $active->setValue($client, [999 => $conn]);
        // Deliberately do NOT seed connectionPromises[999] — that is the "already resolved" case.

        $readConnection = $rc->getMethod('readConnection');
        $readConnection->invoke($client, 999);

        expect($client->queueSize())->toBe(0)
            ->and($client->isIdle())->toBeTrue();
    });
});

/*
 * Integration tests below hit the real network. They are skipped unless ASK_LIVE_NET=1
 * is set, so CI runs offline; developers opt in locally to validate pooling end-to-end.
 */
$liveNet = getenv('ASK_LIVE_NET') === '1';

function liveUrl(): string
{
    return 'https://open.er-api.com/v6/latest/USD';
}

describe('HttpsSocketClient (live network)', function () use ($liveNet) {
    beforeEach(function () use ($liveNet) {
        if (!$liveNet) {
            $this->markTestSkipped('Set ASK_LIVE_NET=1 to run live-network socket tests.');
        }
    });

    it('completes a request with pooling disabled (legacy parity)', function () {
        $client = new HttpsSocketClient(new HttpsSocketClientConfig(keepAlive: false));

        $promise = $client->request(new ASKHttpRequest(url: liveUrl(), method: 'GET'));
        $client->drain();

        $response = $promise->await();

        expect($response->getStatusCode())->toBe(200)
            ->and($client->idlePoolSize())->toBe(0);
    });

    it('reuses a keep-alive connection across two sequential requests', function () {
        $client = new HttpsSocketClient();

        $first = $client->request(new ASKHttpRequest(url: liveUrl(), method: 'GET'));
        $client->drain();
        $firstStatus = $first->await()->getStatusCode();

        // After the first response the connection should sit idle in the pool.
        expect($client->idlePoolSize())->toBe(1);

        $second = $client->request(new ASKHttpRequest(url: liveUrl(), method: 'GET'));
        $client->drain();
        $secondStatus = $second->await()->getStatusCode();

        expect($firstStatus)->toBe(200)
            ->and($secondStatus)->toBe(200)
            ->and($client->idlePoolSize())->toBe(1);
    });

    it('warms up connections into the pool', function () {
        $client = new HttpsSocketClient(new HttpsSocketClientConfig(
            maxIdlePerHost: 4,
        ));

        $warmed = $client->warmUp('open.er-api.com', 3);

        expect($warmed)->toBe(3)
            ->and($client->idlePoolSize())->toBe(3);
    });
});
