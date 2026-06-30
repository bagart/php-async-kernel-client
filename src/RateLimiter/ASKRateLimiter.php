<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\RateLimiter;

use BAGArt\ASKClient\Contracts\RateLimiter\RateLimiterContract;
use BAGArt\AsyncKernel\Contracts\ASKClockContract;
use BAGArt\AsyncKernel\Wrappers\ASKCacheWrapper;

class ASKRateLimiter implements RateLimiterContract
{
    public const string NAME = 'basic';

    private const int DEFAULT_WINDOW_SECONDS = 60;
    private const int DEFAULT_MAX_REQUESTS = 300;

    public function __construct(
        protected readonly ASKCacheWrapper $cache,
        protected readonly ASKClockContract $clock,
    ) {
    }

    public function getRetryDelay(string $key): float
    {
        $floodUntil = (int)($this->cache->get('ask:floodwait:'.$key) ?? 0);
        $now = $this->clock->hrtime();

        if ($floodUntil > $now) {
            return ($floodUntil - $now) / ASKClockContract::NS_PER_SEC;
        }

        $current = (int)($this->cache->get('ask:rate_limit:'.$key) ?? 0);

        return $current < self::DEFAULT_MAX_REQUESTS ? 0.0 : (float)self::DEFAULT_WINDOW_SECONDS;
    }

    public function markSent(string $key): void
    {
        // Use increment for atomicity if your CacheWrapper supports it
        // Or handle the lock/race condition here
        $this->cache->increment('ask:rate_limit:'.$key, 1, self::DEFAULT_WINDOW_SECONDS);
    }

    public function registerRetryAfter(string $key, float $seconds): void
    {
        $until = $this->clock->hrtime() + (int)($seconds * ASKClockContract::NS_PER_SEC);

        $this->cache->put(
            'ask:floodwait:'.$key,
            $until,
            (int)ceil($seconds) + 60
        );
    }

    public function reset(string $key): void
    {
        $this->cache->forget('ask:rate_limit:'.$key);
        $this->cache->forget('ask:floodwait:'.$key);
    }
}
