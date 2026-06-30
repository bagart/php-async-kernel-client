<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Retry;

use BAGArt\ASKClient\ASKFuture;
use BAGArt\ASKClient\ASKNextHandler;
use BAGArt\ASKClient\Contracts\ASKContextContract;
use BAGArt\ASKClient\Contracts\ASKFutureContract;
use BAGArt\ASKClient\Contracts\ASKHandlerContract;
use BAGArt\AsyncKernel\ASK;

final class RetryMiddleware implements ASKHandlerContract
{
    public function __construct(
        private readonly int $maxRetries = 3,
        private readonly int $baseDelayMs = 100,
        private readonly ?\Closure $shouldRetry = null,
    ) {
    }

    public function __invoke(
        object $operation,
        ASKContextContract $context,
        ASKNextHandler $next,
    ): ASKFutureContract {
        return $this->attempt($operation, $context, $next, 0);
    }

    private function attempt(
        object $operation,
        ASKContextContract $context,
        ASKNextHandler $next,
        int $attempt,
    ): ASKFutureContract {
        $ctx = $context->with('retry_attempt', $attempt);

        try {
            $future = $next($operation, $ctx);
            $future->await();

            return $future;
        } catch (\Throwable $error) {
            if ($attempt >= $this->maxRetries) {
                return ASKFuture::failed($error);
            }

            if ($this->shouldRetry !== null && !($this->shouldRetry)($error, $attempt)) {
                return ASKFuture::failed($error);
            }

            // Cooperative backoff: yield to the kernel's timer. Inside a
            // Fiber this suspends so other work can progress; the sync
            // fallback in ASKSleepAwaitable::await() handles non-Fiber callers.
            $delay = $this->baseDelayMs * (2 ** $attempt);
            ASK::sleep($delay)->await();

            return $this->attempt($operation, $context, $next, $attempt + 1);
        }
    }
}
