<?php

declare(strict_types=1);

use BAGArt\ASKClient\Lockers\InMemoryLocker;

describe('InMemoryLocker', function () {
    it('acquires a free key', function () {
        $locker = new InMemoryLocker();

        expect($locker->acquire('chat:1'))->toBeTrue();
    });

    it('rejects a second acquire of an already-locked key', function () {
        $locker = new InMemoryLocker();

        $locker->acquire('chat:1');

        expect($locker->acquire('chat:1'))->toBeFalse();
    });

    it('allows re-acquire after release', function () {
        $locker = new InMemoryLocker();

        $locker->acquire('chat:1');
        $locker->release('chat:1');

        expect($locker->acquire('chat:1'))->toBeTrue();
    });

    it('treats different keys independently', function () {
        $locker = new InMemoryLocker();

        $locker->acquire('chat:1');

        expect($locker->acquire('chat:2'))->toBeTrue();
    });

    it('release is a no-op for an unacquired key', function () {
        $locker = new InMemoryLocker();

        expect(fn () => $locker->release('never-locked'))->not->toThrow(Throwable::class);
    });
});

describe('InMemoryLocker::acquireWithTtl', function () {
    it('acquires with explicit owner', function () {
        $locker = new InMemoryLocker();

        expect($locker->acquireWithTtl('chat:1', 60, 'owner-A'))->toBeTrue();
    });

    it('rejects a second acquire with a different owner', function () {
        $locker = new InMemoryLocker();

        $locker->acquireWithTtl('chat:1', 60, 'owner-A');

        expect($locker->acquireWithTtl('chat:1', 60, 'owner-B'))->toBeFalse();
    });

    it('rejects a second acquire with the same owner (not re-entrant)', function () {
        $locker = new InMemoryLocker();

        $locker->acquireWithTtl('chat:1', 60, 'owner-A');

        expect($locker->acquireWithTtl('chat:1', 60, 'owner-A'))->toBeFalse();
    });

    it('TTL=0 means lock without expiry (backward-compatible with old acquire)', function () {
        $locker = new InMemoryLocker();

        expect($locker->acquireWithTtl('chat:1', 0))->toBeTrue()
            ->and($locker->acquireWithTtl('chat:1', 0))->toBeFalse();
    });
});

describe('InMemoryLocker::releaseWithOwner', function () {
    it('releases when owner matches', function () {
        $locker = new InMemoryLocker();

        $locker->acquireWithTtl('chat:1', 60, 'owner-A');
        $locker->releaseWithOwner('chat:1', 'owner-A');

        // Key is free → new acquire succeeds.
        expect($locker->acquireWithTtl('chat:1', 60, 'owner-B'))->toBeTrue();
    });

    it('does NOT release when owner does not match (safe release)', function () {
        $locker = new InMemoryLocker();

        $locker->acquireWithTtl('chat:1', 60, 'owner-A');
        $locker->releaseWithOwner('chat:1', 'owner-B');

        // Foreign owner did not release the lock → new acquire is still blocked.
        expect($locker->acquireWithTtl('chat:1', 60, 'owner-C'))->toBeFalse();
    });

    it('release with owner=null releases unconditionally (back-compat release)', function () {
        $locker = new InMemoryLocker();

        $locker->acquireWithTtl('chat:1', 60, 'owner-A');
        $locker->releaseWithOwner('chat:1', null);

        expect($locker->acquireWithTtl('chat:1', 60, 'owner-B'))->toBeTrue();
    });

    it('release of an unacquired key is a no-op', function () {
        $locker = new InMemoryLocker();

        expect(fn () => $locker->releaseWithOwner('never', 'owner-X'))->not->toThrow(Throwable::class);
    });
});

describe('InMemoryLocker TTL expiry', function () {
    it('treats an expired lock as free on the next acquire (lazy expiry)', function () {
        $locker = new InMemoryLocker();

        // TTL=1s — acquire, wait for expiry.
        $locker->acquireWithTtl('chat:1', 1, 'owner-A');
        sleep(2);

        // After TTL expiry — new acquire should succeed (lazy expiry in acquire).
        expect($locker->acquireWithTtl('chat:1', 1, 'owner-B'))->toBeTrue();
    });

    it('a non-expired lock still blocks', function () {
        $locker = new InMemoryLocker();

        $locker->acquireWithTtl('chat:1', 60, 'owner-A');

        expect($locker->acquireWithTtl('chat:1', 60, 'owner-B'))->toBeFalse();
    });
});
