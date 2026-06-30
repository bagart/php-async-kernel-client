<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKContext;
use BAGArt\ASKClient\ASKFuture;
use BAGArt\ASKClient\ASKNextHandler;
use BAGArt\ASKClient\Contracts\ASKContextContract;
use BAGArt\ASKClient\Contracts\ASKFutureContract;

describe('ASKNextHandler', function () {
    it('delegates invocation to the wrapped callable', function () {
        $receivedOp = null;
        $receivedCtx = null;

        $handler = ASKNextHandler::wrap(
            function (object $op, ASKContextContract $ctx) use (&$receivedOp, &$receivedCtx): ASKFutureContract {
                $receivedOp = $op;
                $receivedCtx = $ctx;

                return ASKFuture::resolved('delegated');
            },
        );

        $op = new stdClass();
        $op->name = 'test';
        $ctx = ASKContext::from(['key' => 'val']);

        $future = $handler($op, $ctx);

        expect($future)->toBeInstanceOf(ASKFutureContract::class);
        expect($future->await())->toBe('delegated');
        expect($receivedOp)->toBe($op);
        expect($receivedCtx->get('key'))->toBe('val');
    });

    it('passes operation and context through to the next handler unchanged', function () {
        $handler = ASKNextHandler::wrap(
            fn (object $op, ASKContextContract $ctx): ASKFutureContract => ASKFuture::resolved([
                'op' => $op,
                'ctx' => $ctx->all(),
            ]),
        );

        $op = new stdClass();
        $result = $handler($op, ASKContext::from(['a' => 1]))->await();

        expect($result['op'])->toBe($op);
        expect($result['ctx'])->toBe(['a' => 1]);
    });
});
