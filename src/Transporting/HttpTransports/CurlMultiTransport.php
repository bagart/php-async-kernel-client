<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Transporting\HttpTransports;

use BAGArt\ASKClient\Client\CurlMultiClient;
use BAGArt\ASKClient\Contracts\Transporting\HttpTransportContract;
use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\ASKClient\Response\ASKHttpResponse;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Promise\ASKPromise;

final class CurlMultiTransport implements HttpTransportContract, ASKTickableContract
{
    public const string TYPE = 'curl-multi';

    /** @var ASKPromiseContract[] */
    private array $pending = [];

    public function __construct(
        private readonly CurlMultiClient $client = new CurlMultiClient(),
    ) {
    }

    public function request(ASKHttpRequest $request): ASKHttpResponse
    {
        return $this->requestAsync($request)->await();
    }

    public function requestAsync(ASKHttpRequest $request): ASKPromiseContract
    {
        $innerPromise = $this->client->request($request);

        $promise = new ASKPromise(...$this->tickable());
        $id = spl_object_id($innerPromise);
        $this->pending[$id] = $promise;

        $innerPromise->then(
            function (mixed $value) use ($id): mixed {
                unset($this->pending[$id]);
                return $value;
            },
            function (\Throwable $e) use ($id): never {
                unset($this->pending[$id]);
                throw $e;
            },
        );

        $innerPromise->then(
            fn (mixed $value) => $promise->resolve($value),
            fn (\Throwable $e) => $promise->reject($e),
        );

        return $promise;
    }

    public function tick(int $systemPressure): void
    {
        $this->client->tick();
    }

    public function pressure(): int
    {
        return 0;
    }

    public function isIdle(): bool
    {
        return $this->pending === [] && $this->client->isIdle();
    }

    public function queueSize(): int
    {
        return count($this->pending);
    }

    public function tickable(): array
    {
        return [$this, ...$this->client->tickable()];
    }

    public function drain(): void
    {
        while (!$this->isIdle()) {
            $this->tick(0);
        }
    }
}
