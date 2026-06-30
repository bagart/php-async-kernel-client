<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\RateLimiter;

/**
 * Universal interface for rate limiting with support for predictive pacing
 * and reactive server-side feedback.
 */
interface RateLimiterContract
{
    /**
     * Returns the duration in seconds to wait before the next allowed request.
     * Returns 0.0 if the request can proceed immediately.
     */
    public function getRetryDelay(string $key): float;

    /**
     * Signals that a request has been dispatched. Used by predictive
     * limiters to update pacing state.
     */
    public function markSent(string $key): void;

    /**
     * Registers a mandatory wait time (e.g., Retry-After) provided
     * by the API to handle rate limit errors.
     */
    public function registerRetryAfter(string $key, float $seconds): void;

    /**
     * Resets all restrictions associated with the given key.
     */
    public function reset(string $key): void;
}
