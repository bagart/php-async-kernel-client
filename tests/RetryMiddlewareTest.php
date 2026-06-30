<?php

declare(strict_types=1);

use BAGArt\ASKClient\ASKClient;
use BAGArt\ASKClient\ASKFuture;
use BAGArt\ASKClient\ASKTransport;
use BAGArt\ASKClient\Contracts\ASKFutureContract;
use BAGArt\ASKClient\Retry\RetryMiddleware;
use BAGArt\AsyncKernel\ASK;
use BAGArt\AsyncKernel\Timer\ASKTimer;

describe('RetryMiddleware', function () {
    beforeEach(function () {
        ASK::setTimer(new ASKTimer());
    });

    it('retries on failure and eventually succeeds', function () {
        $attempts = 0;

        $transport = ASKTransport::wrap(function () use (&$attempts): ASKFutureContract {
            $attempts++;

            if ($attempts < 3) {
                return ASKFuture::failed(new RuntimeException("attempt {$attempts}"));
            }

            return ASKFuture::resolved('success');
        });

        $client = new ASKClient(
            transport: $transport,
            handlers: [new RetryMiddleware(maxRetries: 5, baseDelayMs: 0)],
        );

        $result = $client->execute(new stdClass())->await();

        expect($result)->toBe('success');
        expect($attempts)->toBe(3);
    });

    it('returns failed future when maxRetries is exceeded', function () {
        $attempts = 0;

        $transport = ASKTransport::wrap(function () use (&$attempts): ASKFutureContract {
            $attempts++;

            return ASKFuture::failed(new RuntimeException("attempt {$attempts}"));
        });

        $client = new ASKClient(
            transport: $transport,
            handlers: [new RetryMiddleware(maxRetries: 2, baseDelayMs: 0)],
        );

        $future = $client->execute(new stdClass());

        expect($future->isSuccessful())->toBeFalse();
        expect($future->getError())->toBeInstanceOf(RuntimeException::class);
        expect($attempts)->toBe(3);
    });

    it('does not retry when shouldRetry returns false', function () {
        $attempts = 0;

        $transport = ASKTransport::wrap(function () use (&$attempts): ASKFutureContract {
            $attempts++;

            return ASKFuture::failed(new LogicException('non-retryable'));
        });

        $shouldRetry = fn (Throwable $e): bool => !$e instanceof LogicException;

        $client = new ASKClient(
            transport: $transport,
            handlers: [new RetryMiddleware(maxRetries: 5, baseDelayMs: 0, shouldRetry: $shouldRetry)],
        );

        $future = $client->execute(new stdClass());

        expect($future->isSuccessful())->toBeFalse();
        expect($future->getError())->toBeInstanceOf(LogicException::class);
        expect($attempts)->toBe(1);
    });

    it('integrates: retry + failing transport → eventual success', function () {
        $attempts = 0;

        $transport = ASKTransport::wrap(function () use (&$attempts): ASKFutureContract {
            $attempts++;

            if ($attempts < 4) {
                return ASKFuture::failed(new RuntimeException('not yet'));
            }

            return ASKFuture::resolved(['data' => 'payload']);
        });

        $client = new ASKClient(
            transport: $transport,
            handlers: [new RetryMiddleware(maxRetries: 5, baseDelayMs: 1)],
        );

        $result = $client->execute(new stdClass())->await();

        expect($result)->toBe(['data' => 'payload']);
        expect($attempts)->toBe(4);
    });
});
