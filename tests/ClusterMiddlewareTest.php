<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKClient;
use BAGArt\ASKClient\ASKFuture;
use BAGArt\ASKClient\ASKTransport;
use BAGArt\ASKClient\ClusterMiddleware;
use BAGArt\ASKClient\Contracts\ASKContextContract;

describe('ClusterMiddleware', function () {
    it('stamps cluster.node into context and still calls $next', function () {
        $captured = null;
        $transportCalled = false;

        $client = new ASKClient(
            transport: ASKTransport::wrap(function (object $op, ASKContextContract $ctx) use (&$captured, &$transportCalled): \BAGArt\ASKClient\Contracts\ASKFutureContract {
                $captured = $ctx->get(ClusterMiddleware::NODE_KEY);
                $transportCalled = true;

                return ASKFuture::resolved('ok');
            }),
            handlers: [new ClusterMiddleware(['node-a', 'node-b'])],
        );

        $result = $client->execute(new stdClass())->await();

        expect($result)->toBe('ok');
        expect($transportCalled)->toBeTrue();
        expect($captured)->toBe('node-a');
    });

    it('round-robins across nodes over successive calls', function () {
        $captured = [];

        $client = new ASKClient(
            transport: ASKTransport::wrap(function (object $op, ASKContextContract $ctx) use (&$captured): \BAGArt\ASKClient\Contracts\ASKFutureContract {
                $captured[] = $ctx->get(ClusterMiddleware::NODE_KEY);

                return ASKFuture::resolved(null);
            }),
            handlers: [new ClusterMiddleware(['a', 'b'])],
        );

        foreach (range(1, 4) as $_) {
            $client->execute(new stdClass())->await();
        }

        expect($captured)->toBe(['a', 'b', 'a', 'b']);
    });

    it('uses a custom router that can analyse the context', function () {
        $captured = null;

        $router = fn (object $op, ASKContextContract $ctx): string => $ctx->get('shard', 'fallback');

        $client = new ASKClient(
            transport: ASKTransport::wrap(function (object $op, ASKContextContract $ctx) use (&$captured): \BAGArt\ASKClient\Contracts\ASKFutureContract {
                $captured = $ctx->get(ClusterMiddleware::NODE_KEY);

                return ASKFuture::resolved(null);
            }),
            handlers: [new ClusterMiddleware(['a', 'b'], $router)],
        );

        $client->execute(new stdClass(), \BAGArt\ASKClient\ASKContext::from(['shard' => 'b']))->await();

        expect($captured)->toBe('b');
    });

    it('does not short-circuit: downstream handlers and transport still run', function () {
        $log = new stdClass();
        $log->entries = [];

        $inner = new class ($log) implements \BAGArt\ASKClient\Contracts\ASKHandlerContract {
            public function __construct(private readonly stdClass $log)
            {
            }

            public function __invoke(object $operation, ASKContextContract $context, BAGArt\ASKClient\ASKNextHandler $next): \BAGArt\ASKClient\Contracts\ASKFutureContract
            {
                $this->log->entries[] = 'inner';

                return $next($operation, $context);
            }
        };

        $client = new ASKClient(
            transport: ASKTransport::wrap(function () use ($log): \BAGArt\ASKClient\Contracts\ASKFutureContract {
                $log->entries[] = 'transport';

                return ASKFuture::resolved(null);
            }),
            handlers: [new ClusterMiddleware(['a']), $inner],
        );

        $client->execute(new stdClass())->await();

        expect($log->entries)->toBe(['inner', 'transport']);
    });

    it('rejects an empty node list', function () {
        expect(fn () => new ClusterMiddleware([]))->toThrow(InvalidArgumentException::class);
    });
});
