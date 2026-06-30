<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKClient;
use BAGArt\ASKClient\ASKFuture;
use BAGArt\ASKClient\ASKNextHandler;
use BAGArt\ASKClient\ASKTransport;
use BAGArt\ASKClient\Contracts\ASKContextContract;
use BAGArt\ASKClient\Contracts\ASKFutureContract;
use BAGArt\ASKClient\Contracts\ASKHandlerContract;

describe('Handler chain', function () {
    it('executes handlers in declaration order (outer first)', function () {
        $log = new stdClass();
        $log->entries = [];

        $makeHandler = function (string $name) use ($log): ASKHandlerContract {
            return new class ($log, $name) implements ASKHandlerContract {
                public function __construct(
                    private readonly stdClass $log,
                    private readonly string $name,
                ) {
                }

                public function __invoke(object $operation, ASKContextContract $context, ASKNextHandler $next): ASKFutureContract
                {
                    $this->log->entries[] = "{$this->name}:before";

                    return $next($operation, $context)->then(function () {
                        $this->log->entries[] = "{$this->name}:after";
                    });
                }
            };
        };

        $client = new ASKClient(
            transport: ASKTransport::wrap(function () use ($log) {
                $log->entries[] = 'transport';

                return ASKFuture::resolved(null);
            }),
            handlers: [$makeHandler('h1'), $makeHandler('h2')],
        );

        $client->execute(new stdClass())->await();

        expect($log->entries)->toBe([
            'h1:before',
            'h2:before',
            'transport',
            'h2:after',
            'h1:after',
        ]);
    });

    it('passes modified context from handler to transport', function () {
        $captured = null;

        $handler = new class () implements ASKHandlerContract {
            public function __invoke(object $operation, ASKContextContract $context, ASKNextHandler $next): ASKFutureContract
            {
                return $next($operation, $context->with('injected', 'value'));
            }
        };

        $client = new ASKClient(
            transport: ASKTransport::wrap(function (object $op, ASKContextContract $ctx) use (&$captured) {
                $captured = $ctx->get('injected');

                return ASKFuture::resolved(null);
            }),
            handlers: [$handler],
        );

        $client->execute(new stdClass())->await();

        expect($captured)->toBe('value');
    });

    it('allows a handler to short-circuit and skip transport', function () {
        $transportCalled = false;

        $handler = new class () implements ASKHandlerContract {
            public function __invoke(object $operation, ASKContextContract $context, ASKNextHandler $next): ASKFutureContract
            {
                return ASKFuture::resolved('short-circuit');
            }
        };

        $client = new ASKClient(
            transport: ASKTransport::wrap(function () use (&$transportCalled) {
                $transportCalled = true;

                return ASKFuture::resolved('transport');
            }),
            handlers: [$handler],
        );

        $result = $client->execute(new stdClass())->await();

        expect($result)->toBe('short-circuit');
        expect($transportCalled)->toBeFalse();
    });

    it('allows a handler to mutate the operation before delegating', function () {
        $captured = null;

        $handler = new class () implements ASKHandlerContract {
            public function __invoke(object $operation, ASKContextContract $context, ASKNextHandler $next): ASKFutureContract
            {
                $operation->modified = true;

                return $next($operation, $context);
            }
        };

        $client = new ASKClient(
            transport: ASKTransport::wrap(function (object $op, ASKContextContract $ctx) use (&$captured) {
                $captured = $op;

                return ASKFuture::resolved(null);
            }),
            handlers: [$handler],
        );

        $op = new stdClass();
        $client->execute($op)->await();

        expect($captured->modified)->toBeTrue();
    });

    it('chains 3+ handlers in correct nested order', function () {
        $log = new stdClass();
        $log->entries = [];

        $makeHandler = function (string $name) use ($log): ASKHandlerContract {
            return new class ($log, $name) implements ASKHandlerContract {
                public function __construct(
                    private readonly stdClass $log,
                    private readonly string $name,
                ) {
                }

                public function __invoke(object $operation, ASKContextContract $context, ASKNextHandler $next): ASKFutureContract
                {
                    $this->log->entries[] = "{$this->name}:in";

                    return $next($operation, $context)->then(function () {
                        $this->log->entries[] = "{$this->name}:out";
                    });
                }
            };
        };

        $client = new ASKClient(
            transport: ASKTransport::wrap(function () use ($log) {
                $log->entries[] = 'transport';

                return ASKFuture::resolved(null);
            }),
            handlers: [$makeHandler('a'), $makeHandler('b'), $makeHandler('c')],
        );

        $client->execute(new stdClass())->await();

        expect($log->entries)->toBe([
            'a:in', 'b:in', 'c:in',
            'transport',
            'c:out', 'b:out', 'a:out',
        ]);
    });
});
