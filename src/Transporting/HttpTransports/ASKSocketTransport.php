<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Transporting\HttpTransports;

use BAGArt\ASKClient\Client\HttpsSocketClient\HttpsSocketClient;
use BAGArt\ASKClient\Client\HttpsSocketClient\HttpsSocketClientConfig;
use BAGArt\ASKClient\Contracts\Client\WarmableClientContract;
use BAGArt\ASKClient\Contracts\Transporting\HttpTransportContract;
use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\ASKClient\Response\ASKHttpResponse;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Promise\ASKPromise;

final class ASKSocketTransport implements
    HttpTransportContract,
    WarmableClientContract,
    ASKTickableContract
{
    public const string TYPE = 'ask-socket';

    /** @var ASKPromiseContract[] */
    private array $pending = [];

    public function __construct(
        private readonly HttpsSocketClient $client = new HttpsSocketClient(),
    ) {
    }

    /**
     * Build a transport from a config DTO, constructing the underlying client with it.
     * Convenience for callers (daemons, service providers) that want pooling without
     * instantiating HttpsSocketClient themselves.
     */
    public static function withConfig(HttpsSocketClientConfig $config): self
    {
        return new self(new HttpsSocketClient($config));
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
        $this->client->tick($systemPressure);
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

    /**
     * Pre-open {@see $count} kept-open connections to {@see $host} and park them in the
     * client's pool. No-op when keep-alive is disabled. Delegates to the underlying client.
     */
    public function warmUp(string $host, int $count, int $port = 443): int
    {
        return $this->client->warmUp($host, $count, $port);
    }
}
