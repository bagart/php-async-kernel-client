<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Network;

use Throwable;

/**
 * Universal retry policy for failed operations.
 */
interface RetryPolicyContract
{
    public const MAX_ATTEMPTS = 20;
    public const BASE_DELAY_MS = 1000;

    /**
     * Determine if the request should be retried.
     */
    public function shouldRetry(
        string $method,
        int $attempt,
        Throwable $error,
    ): bool;

    /**
     * Get delay in seconds before next retry attempt.
     */
    public function getDelay(int $attempt): int;

    /**
     * Get maximum number of retry attempts.
     */
    public function getMaxAttempts(): int;
}
