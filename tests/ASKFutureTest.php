<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKFuture;

describe('ASKFuture', function () {
    describe('resolved()', function () {
        it('returns the value on await()', function () {
            expect(ASKFuture::resolved(42)->await())->toBe(42);
        });

        it('is successful', function () {
            $f = ASKFuture::resolved('ok');

            expect($f->isCompleted())->toBeTrue();
            expect($f->isSuccessful())->toBeTrue();
            expect($f->getError())->toBeNull();
        });
    });

    describe('failed()', function () {
        it('throws on await()', function () {
            $error = new RuntimeException('boom');

            expect(fn () => ASKFuture::failed($error)->await())->toThrow($error);
        });

        it('reports error state without throwing', function () {
            $error = new LogicException('bad');
            $f = ASKFuture::failed($error);

            expect($f->isCompleted())->toBeTrue();
            expect($f->isSuccessful())->toBeFalse();
            expect($f->getError())->toBe($error);
        });
    });

    describe('pending()', function () {
        it('does not execute callback until awaited', function () {
            $executed = false;
            $f = ASKFuture::pending(function () use (&$executed) {
                $executed = true;

                return 1;
            });

            expect($executed)->toBeFalse();
            expect($f->await())->toBe(1);
            expect($executed)->toBeTrue();
        });

        it('captures exceptions as errors', function () {
            $f = ASKFuture::pending(function (): never {
                throw new RuntimeException('fail');
            });

            expect($f->isCompleted())->toBeTrue();
            expect($f->isSuccessful())->toBeFalse();
            expect($f->getError())->toBeInstanceOf(RuntimeException::class);
        });

        it('resolves only once', function () {
            $calls = 0;
            $f = ASKFuture::pending(function () use (&$calls) {
                $calls++;

                return $calls;
            });

            $f->await();
            $f->await();

            expect($calls)->toBe(1);
        });
    });

    describe('then()', function () {
        it('returns a new Future that transforms the value', function () {
            $result = ASKFuture::resolved(1)
                ->then(fn ($x) => $x + 1)
                ->then(fn ($x) => $x * 2)
                ->await();

            expect($result)->toBe(4);
        });

        it('does not execute the callback if the source fails', function () {
            $called = false;

            $f = ASKFuture::failed(new RuntimeException())
                ->then(function () use (&$called) {
                    $called = true;
                });

            expect(fn () => $f->await())->toThrow(RuntimeException::class);
            expect($called)->toBeFalse();
        });

        it('returns a new Future instance', function () {
            $source = ASKFuture::resolved(1);
            $derived = $source->then(fn ($x) => $x + 1);

            expect($derived)->not->toBe($source);
            expect($derived)->toBeInstanceOf(ASKFuture::class);
        });
    });

    describe('catch()', function () {
        it('recovers from an error', function () {
            $result = ASKFuture::failed(new RuntimeException('boom'))
                ->catch(fn () => 'recovered')
                ->await();

            expect($result)->toBe('recovered');
        });

        it('does not invoke the callback on success', function () {
            $called = false;

            $result = ASKFuture::resolved('ok')
                ->catch(function () use (&$called) {
                    $called = true;

                    return 'should not happen';
                })
                ->await();

            expect($result)->toBe('ok');
            expect($called)->toBeFalse();
        });

        it('returns a new Future instance', function () {
            $source = ASKFuture::failed(new RuntimeException());
            $derived = $source->catch(fn () => null);

            expect($derived)->not->toBe($source);
        });
    });

    describe('recover()', function () {
        it('transforms an error into a successful result', function () {
            $result = ASKFuture::failed(new RuntimeException('boom'))
                ->recover(fn (Throwable $e) => [])
                ->await();

            expect($result)->toBe([]);
        });

        it('is equivalent to catch()', function () {
            $viaCatch = ASKFuture::failed(new RuntimeException())->catch(fn () => 1)->await();
            $viaRecover = ASKFuture::failed(new RuntimeException())->recover(fn () => 1)->await();

            expect($viaCatch)->toBe($viaRecover);
        });
    });

    describe('finally()', function () {
        it('is called after success', function () {
            $called = false;

            ASKFuture::resolved('ok')
                ->finally(function () use (&$called) {
                    $called = true;
                })
                ->await();

            expect($called)->toBeTrue();
        });

        it('is called after error even without catch', function () {
            $called = false;

            $f = ASKFuture::failed(new RuntimeException('boom'))
                ->finally(function () use (&$called) {
                    $called = true;
                });

            expect(fn () => $f->await())->toThrow(RuntimeException::class);
            expect($called)->toBeTrue();
        });

        it('preserves the original error after finally', function () {
            $error = new RuntimeException('original');

            $f = ASKFuture::failed($error)
                ->finally(fn () => null);

            expect(fn () => $f->await())->toThrow($error);
        });

        it('runs before catch handles the error in chain', function () {
            $order = [];

            ASKFuture::failed(new RuntimeException())
                ->finally(function () use (&$order) {
                    $order[] = 'finally';
                })
                ->catch(function () use (&$order) {
                    $order[] = 'catch';
                })
                ->await();

            expect($order)->toBe(['finally', 'catch']);
        });
    });

    describe('error propagation', function () {
        it('then() callback throws → error propagates', function () {
            $f = ASKFuture::resolved(1)->then(fn ($x) => throw new RuntimeException('transform failed'));

            expect($f->isSuccessful())->toBeFalse();
            expect($f->getError()->getMessage())->toBe('transform failed');
        });

        it('catch() callback throws → new error propagates', function () {
            $f = ASKFuture::failed(new RuntimeException('original'))
                ->catch(fn (Throwable $e) => throw new LogicException('recovery failed'));

            expect($f->isSuccessful())->toBeFalse();
            expect($f->getError())->toBeInstanceOf(LogicException::class);
            expect($f->getError()->getMessage())->toBe('recovery failed');
        });

        it('catch().then() — recovered value flows through then', function () {
            $result = ASKFuture::failed(new RuntimeException('boom'))
                ->catch(fn () => 10)
                ->then(fn ($x) => $x + 5)
                ->await();

            expect($result)->toBe(15);
        });

        it('then().catch() — then throws, catch recovers', function () {
            $result = ASKFuture::resolved(1)
                ->then(fn ($x) => throw new RuntimeException('transform error'))
                ->catch(fn (Throwable $e) => 'recovered-from-then')
                ->await();

            expect($result)->toBe('recovered-from-then');
        });

        it('multiple then() on same source → independent branches', function () {
            $source = ASKFuture::resolved(10);

            $branch1 = $source->then(fn ($x) => $x + 1);
            $branch2 = $source->then(fn ($x) => $x * 2);

            expect($branch1->await())->toBe(11);
            expect($branch2->await())->toBe(20);
            expect($source->await())->toBe(10);
        });
    });
});
