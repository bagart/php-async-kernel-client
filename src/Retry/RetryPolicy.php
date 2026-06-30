<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Retry;

use BAGArt\ASKClient\Contracts\Network\RetryPolicyContract;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\ASKClient\Exceptions\ASKRateLimitException;
use Throwable;

/**
 * Universal retry policy with exponential backoff.
 *
 * Subclasses may override isRetryableException() to add domain-specific
 * exception types and shouldRetry() to add method-specific exclusions.
 */
class RetryPolicy implements RetryPolicyContract
{
    public function shouldRetry(
        string $method,
        int $attempt,
        Throwable $error,
    ): bool {
        if ($attempt >= $this->getMaxAttempts()) {
            return false;
        }

        if (!$this->isRetryableException($error)) {
            return false;
        }

        return $this->isRetryableMessage($error->getMessage());
    }

    public function getDelay(int $attempt): int
    {
        $delay = self::BASE_DELAY_MS * (2 ** ($attempt - 1));

        return (int)($delay / 1000);
    }

    public function getMaxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    /**
     * Check if the exception type is eligible for retry.
     *
     * Override in subclasses to add domain-specific exception types.
     */
    protected function isRetryableException(Throwable $error): bool
    {
        return $error instanceof ASKNetworkException
            || $error instanceof ASKRateLimitException;
    }

    /**
     * Check if the error message indicates a retryable condition.
     *
     * Matches common patterns: timeout, cURL errors, rate limiting, HTTP 429.
     */
    protected function isRetryableMessage(string $message): bool
    {
        if (str_contains($message, 'Timed out') || str_contains($message, 'cURL error 28')) {
            return true;
        }

        if (str_contains(mb_strtolower($message), 'rate limit')) {
            return true;
        }

        if (str_contains($message, '429')) {
            return true;
        }

        return false;
    }
}
