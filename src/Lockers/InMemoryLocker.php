<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Lockers;

use BAGArt\AsyncKernel\Contracts\ASKLockerContract;

/**
 * In-memory locker implementation (for tests and standalone mode).
 *
 * Stores locks as map[key => [owner, expiresAt]]. TTL — lazy check: an expired
 * lock is considered free on the next acquire and is overwritten.
 *
 * Supports both old methods {@see acquire()}/{@see release()} (no owner, no TTL —
 * for back-compat) and new {@see acquireWithTtl()}/{@see releaseWithOwner()}
 * (with TTL and owner — for ordering lock, todo.md §3.5).
 *
 * NOT thread-safe (single process). For multi-process use RedisLocker.
 */
final class InMemoryLocker implements ASKLockerContract
{
    /**
     * @var array<string, array{owner: string, expiresAt: int|null}>
     */
    private array $locks = [];

    public function acquire(string $key): bool
    {
        return $this->acquireWithTtl($key, 0, $this->defaultOwner());
    }

    public function release(string $key): void
    {
        $this->releaseWithOwner($key, null);
    }

    public function acquireWithTtl(string $key, int $ttl, ?string $owner = null): bool
    {
        $existing = $this->locks[$key] ?? null;

        // Lazy TTL check: if a lock exists but is expired — treat as free.
        if ($existing !== null && $existing['expiresAt'] !== null && $existing['expiresAt'] <= $this->now()) {
            unset($this->locks[$key]);
            $existing = null;
        }

        if ($existing !== null) {
            return false;
        }

        $this->locks[$key] = [
            'owner' => $owner ?? $this->defaultOwner(),
            'expiresAt' => $ttl > 0 ? $this->now() + $ttl : null,
        ];

        return true;
    }

    public function releaseWithOwner(string $key, ?string $owner = null): void
    {
        if (!isset($this->locks[$key])) {
            return;
        }

        // owner = null → unconditional release (back-compat release() semantics).
        if ($owner === null) {
            unset($this->locks[$key]);

            return;
        }

        // owner specified — delete only if owner matches (safe release).
        if ($this->locks[$key]['owner'] === $owner) {
            unset($this->locks[$key]);
        }
    }

    /**
     * Random owner token for acquire()/acquireWithTtl() without explicit owner.
     *
     * Generated on each call — every acquire without owner gets a unique token,
     * so that releaseWithOwner(owner) works correctly. Semantics: if the caller did not pass
     * an owner, they should release the lock via release() (no owner).
     */
    private function defaultOwner(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function now(): int
    {
        return time();
    }
}
