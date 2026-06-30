<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKClient;
use BAGArt\ASKClient\ASKFuture;
use BAGArt\ASKClient\ASKTransport;
use BAGArt\ASKClient\Contracts\ASKContextContract;
use BAGArt\ASKClient\Contracts\ASKFutureContract;
use BAGArt\ASKClient\MetricsMiddleware;

describe('MetricsMiddleware', function () {
    it('records a success metric with duration after the future settles', function () {
        $records = [];

        $client = new ASKClient(
            transport: ASKTransport::wrap(fn (): ASKFutureContract => ASKFuture::resolved('done')),
            handlers: [new MetricsMiddleware(function (array $r) use (&$records) {
                $records[] = $r;
            })],
        );

        $future = $client->execute(new stdClass());

        expect($records)->toBeEmpty();

        $future->await();

        expect($records)->toHaveCount(1);
        expect($records[0]['outcome'])->toBe('success');
        expect($records[0]['operation'])->toBe('stdClass');
        expect($records[0]['duration_ms'])->toBeGreaterThanOrEqual(0.0);
        expect($records[0]['error'])->toBeNull();
    });

    it('is non-blocking: the sink is not invoked until the future is awaited', function () {
        $records = [];

        $client = new ASKClient(
            transport: ASKTransport::wrap(fn (): ASKFutureContract => ASKFuture::resolved('x')),
            handlers: [new MetricsMiddleware(function (array $r) use (&$records) {
                $records[] = $r;
            })],
        );

        $future = $client->execute(new stdClass());

        expect($records)->toBeEmpty();

        $future->await();

        expect($records)->toHaveCount(1);
    });

    it('records a failure metric and still propagates the error', function () {
        $records = [];

        $client = new ASKClient(
            transport: ASKTransport::wrap(fn (): ASKFutureContract => ASKFuture::failed(new RuntimeException('boom'))),
            handlers: [new MetricsMiddleware(function (array $r) use (&$records) {
                $records[] = $r;
            })],
        );

        $future = $client->execute(new stdClass());

        expect($records)->toBeEmpty();
        expect(fn () => $future->await())->toThrow(RuntimeException::class);

        expect($records)->toHaveCount(1);
        expect($records[0]['outcome'])->toBe('failure');
        expect($records[0]['error'])->toBe('boom');
    });

    it('records exactly once per operation', function () {
        $records = [];

        $client = new ASKClient(
            transport: ASKTransport::wrap(fn (): ASKFutureContract => ASKFuture::resolved('ok')),
            handlers: [new MetricsMiddleware(function (array $r) use (&$records) {
                $records[] = $r;
            })],
        );

        $client->execute(new stdClass())->await();

        expect($records)->toHaveCount(1);
    });

    it('a throwing sink does not break the operation', function () {
        $client = new ASKClient(
            transport: ASKTransport::wrap(fn (): ASKFutureContract => ASKFuture::resolved('ok')),
            handlers: [new MetricsMiddleware(fn () => throw new LogicException('sink down'))],
        );

        expect($client->execute(new stdClass())->await())->toBe('ok');
    });

    it('captures the cluster node when chained after ClusterMiddleware', function () {
        $records = [];
        $routed = [];

        $client = new ASKClient(
            transport: ASKTransport::wrap(function (object $op, ASKContextContract $ctx) use (&$routed): ASKFutureContract {
                $routed[] = $ctx->get(\BAGArt\ASKClient\ClusterMiddleware::NODE_KEY);

                return ASKFuture::resolved(null);
            }),
            handlers: [
                new \BAGArt\ASKClient\ClusterMiddleware(['a', 'b']),
                new MetricsMiddleware(function (array $r) use (&$records) {
                    $records[] = $r;
                }),
            ],
        );

        foreach (range(1, 3) as $_) {
            $client->execute(new stdClass())->await();
        }

        expect($routed)->toBe(['a', 'b', 'a']);
        expect(array_map(fn (array $r) => $r['node'], $records))->toBe(['a', 'b', 'a']);
        expect($records)->each->toHaveKey('outcome', 'success');
    });
});
