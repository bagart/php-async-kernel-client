<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKContext;

describe('ASKContext', function () {
    it('starts empty', function () {
        $ctx = ASKContext::empty();

        expect($ctx->all())->toBe([]);
        expect($ctx->has('anything'))->toBeFalse();
    });

    it('creates from array', function () {
        $ctx = ASKContext::from(['a' => 1, 'b' => 2]);

        expect($ctx->get('a'))->toBe(1);
        expect($ctx->get('b'))->toBe(2);
    });

    describe('with()', function () {
        it('adds a key-value pair', function () {
            $ctx = ASKContext::empty()->with('token', 'abc');

            expect($ctx->get('token'))->toBe('abc');
            expect($ctx->has('token'))->toBeTrue();
        });

        it('returns a new instance (immutable)', function () {
            $original = ASKContext::from(['a' => 1]);
            $derived = $original->with('b', 2);

            expect($derived)->not->toBe($original);
            expect($original->has('b'))->toBeFalse();
            expect($derived->has('b'))->toBeTrue();
        });

        it('overwrites existing keys', function () {
            $ctx = ASKContext::from(['a' => 1])->with('a', 2);

            expect($ctx->get('a'))->toBe(2);
        });
    });

    describe('without()', function () {
        it('removes a key', function () {
            $ctx = ASKContext::from(['a' => 1, 'b' => 2])->without('a');

            expect($ctx->has('a'))->toBeFalse();
            expect($ctx->get('a'))->toBeNull();
            expect($ctx->has('b'))->toBeTrue();
        });

        it('returns a new instance (immutable)', function () {
            $original = ASKContext::from(['a' => 1]);
            $derived = $original->without('a');

            expect($derived)->not->toBe($original);
            expect($original->has('a'))->toBeTrue();
        });

        it('is safe for non-existent keys', function () {
            $ctx = ASKContext::empty()->without('nonexistent');

            expect($ctx->all())->toBe([]);
        });
    });

    describe('merge()', function () {
        it('merges another context with other taking precedence', function () {
            $base = ASKContext::from(['a' => 1, 'b' => 2]);
            $other = ASKContext::from(['b' => 3, 'c' => 4]);

            $merged = $base->merge($other);

            expect($merged->get('a'))->toBe(1);
            expect($merged->get('b'))->toBe(3);
            expect($merged->get('c'))->toBe(4);
        });

        it('returns a new instance (immutable)', function () {
            $base = ASKContext::from(['a' => 1]);
            $other = ASKContext::from(['b' => 2]);

            $merged = $base->merge($other);

            expect($merged)->not->toBe($base);
            expect($base->has('b'))->toBeFalse();
        });
    });

    it('get() returns default for missing keys', function () {
        $ctx = ASKContext::empty();

        expect($ctx->get('missing'))->toBeNull();
        expect($ctx->get('missing', 'default'))->toBe('default');
    });

    it('merge() with empty context returns unchanged data', function () {
        $base = ASKContext::from(['a' => 1, 'b' => 2]);
        $merged = $base->merge(ASKContext::empty());

        expect($merged->all())->toBe(['a' => 1, 'b' => 2]);
    });

    it('with().without().merge() chain preserves immutability', function () {
        $original = ASKContext::from(['a' => 1, 'b' => 2, 'c' => 3]);

        $result = $original
            ->with('d', 4)
            ->without('b')
            ->merge(ASKContext::from(['a' => 10, 'e' => 5]));

        expect($original->all())->toBe(['a' => 1, 'b' => 2, 'c' => 3]);
        expect($result->all())->toBe(['a' => 10, 'c' => 3, 'd' => 4, 'e' => 5]);
        expect($result)->not->toBe($original);
    });
});
