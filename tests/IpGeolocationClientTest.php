<?php

declare(strict_types=1);

use BAGArt\ASKClient\Client\HttpsSocketClient\MemoryStreamFactory;
use BAGArt\ASKClient\Contracts\Client\ApiClientContract;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\ASKClient\Network\IpGeolocation;
use BAGArt\ASKClient\Network\IpGeolocationClient;
use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\ASKClient\Response\ASKHttpResponse;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;

function mockPromise(mixed $value): ASKPromiseContract
{
    return new class ($value) implements ASKPromiseContract {
        public function __construct(
            private readonly mixed $value,
        ) {
        }

        public function then(
            ?callable $onFulfilled = null,
            ?callable $onRejected = null,
        ): ASKPromiseContract {
            if ($onFulfilled !== null) {
                return mockPromise($onFulfilled($this->value));
            }

            return $this;
        }

        public function otherwise(callable $onRejected): ASKPromiseContract
        {
            return $this;
        }

        public function wait(bool $unwrap = true): mixed
        {
            return $this->value;
        }

        public function getState(): string
        {
            return ASKPromiseContract::FULFILLED;
        }

        public function getValue(): mixed
        {
            return $this->value;
        }

        public function getReason(): ?\Throwable
        {
            return null;
        }

        public function cancel(): void
        {
        }
    };
}

function mockApiClient(callable $responder): ApiClientContract
{
    return new class ($responder) implements ApiClientContract {
        public function __construct(
            private readonly mixed $responder,
        ) {
        }

        public function requestAsync(ASKHttpRequest $request): ASKPromiseContract
        {
            return mockPromise(($this->responder)($request));
        }

        public function request(ASKHttpRequest $request): ASKHttpResponse
        {
            return ($this->responder)($request);
        }

        public function tickable(): array
        {
            return [];
        }
    };
}

function jsonResponse(array $data, int $status = 200): ASKHttpResponse
{
    return new ASKHttpResponse(
        protocolVersion: '1.1',
        statusCode: $status,
        reasonPhrase: $status === 200 ? 'OK' : 'Error',
        headers: ['content-type' => ['application/json']],
        body: MemoryStreamFactory::createFromString(
            json_encode($data, JSON_THROW_ON_ERROR),
        ),
    );
}

describe('IpGeolocationClient', function () {
    it('resolves ip, country, isp synchronously', function () {
        $client = new IpGeolocationClient(
            apiClient: mockApiClient(fn () => jsonResponse([
                'status' => 'success',
                'country' => 'Russia',
                'countryCode' => 'RU',
                'isp' => 'Rostelecom',
                'query' => '8.8.8.8',
            ])),
        );

        $result = $client->resolve();

        expect($result)->toBeInstanceOf(IpGeolocation::class);
        expect($result->ip)->toBe('8.8.8.8');
        expect($result->country)->toBe('Russia');
        expect($result->isp)->toBe('Rostelecom');
    });

    it('resolves ip, country, isp asynchronously', function () {
        $client = new IpGeolocationClient(
            apiClient: mockApiClient(fn () => jsonResponse([
                'status' => 'success',
                'country' => 'Germany',
                'countryCode' => 'DE',
                'isp' => 'Deutsche Telekom',
                'query' => '1.2.3.4',
            ])),
        );

        $promise = $client->resolveAsync();

        expect($promise)->toBeInstanceOf(ASKPromiseContract::class);

        $result = $promise->wait();

        expect($result)->toBeInstanceOf(IpGeolocation::class);
        expect($result->ip)->toBe('1.2.3.4');
        expect($result->country)->toBe('Germany');
        expect($result->isp)->toBe('Deutsche Telekom');
    });

    it('throws on non-200 status code', function () {
        $client = new IpGeolocationClient(
            apiClient: mockApiClient(fn () => jsonResponse(
                data: ['status' => 'fail', 'message' => 'rate limited'],
                status: 429,
            )),
        );

        expect(fn () => $client->resolve())->toThrow(
            ASKNetworkException::class,
            'Geolocation API returned HTTP 429',
        );
    });

    it('throws on API error status', function () {
        $client = new IpGeolocationClient(
            apiClient: mockApiClient(fn () => jsonResponse([
                'status' => 'fail',
                'message' => 'invalid query',
            ])),
        );

        expect(fn () => $client->resolve())->toThrow(
            ASKNetworkException::class,
            'Geolocation API error: invalid query',
        );
    });

    it('throws on missing fields in response', function () {
        $client = new IpGeolocationClient(
            apiClient: mockApiClient(fn () => jsonResponse([
                'status' => 'success',
                'country' => 'US',
                'query' => '8.8.8.8',
            ])),
        );

        expect(fn () => $client->resolve())->toThrow(
            ASKNetworkException::class,
            'missing required fields',
        );
    });

    it('throws on invalid JSON', function () {
        $client = new IpGeolocationClient(
            apiClient: mockApiClient(fn () => new ASKHttpResponse(
                protocolVersion: '1.1',
                statusCode: 200,
                reasonPhrase: 'OK',
                headers: ['content-type' => ['application/json']],
                body: MemoryStreamFactory::createFromString('not-json'),
            )),
        );

        expect(fn () => $client->resolve())->toThrow(
            ASKNetworkException::class,
            'Invalid JSON response',
        );
    });
});
