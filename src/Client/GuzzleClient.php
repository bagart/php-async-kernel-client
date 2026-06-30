<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client;

use BAGArt\ASKClient\Contracts\Client\NetworkClientContract;
use BAGArt\ASKClient\Drivers\GuzzleNetworkTickableDriver;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\ASKClient\Promise\GuzzlePromiseAdapter;
use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;

final class GuzzleClient implements NetworkClientContract
{
    private readonly Client $client;
    private readonly GuzzleNetworkTickableDriver $tickableDriver;

    private int $activeRequestsCount = 0;

    public function __construct(
        ?CurlMultiHandler $curlMultiHandler = null,
    ) {
        $curlMultiHandler ??= new CurlMultiHandler(['select_timeout' => 0]);

        $stack = HandlerStack::create($curlMultiHandler);

        $stack->push($this->createTrackingMiddleware(), 'request_tracker');

        $this->client = new Client([
            'handler' => $stack,
        ]);

        $this->tickableDriver = new GuzzleNetworkTickableDriver(
            curlMultiHandler: $curlMultiHandler,
            activeRequestsCount: $this->activeRequestsCount,
        );
    }

    public function request(ASKHttpRequest $request): ASKPromiseContract
    {
        $guzzlePromise = $this->client->requestAsync(
            $request->method,
            $request->getUrlWithQuery(),
            [
                'headers' => $request->headers,
                'body' => $request->body,
            ],
        );

        return GuzzlePromiseAdapter::wrap($guzzlePromise);
    }

    public function tickable(): array
    {
        return [$this->tickableDriver];
    }

    private function createTrackingMiddleware(): callable
    {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                $this->activeRequestsCount++;

                return $handler($request, $options)->then(
                    function (mixed $value): mixed {
                        $this->activeRequestsCount--;
                        return $value;
                    },
                    function (mixed $reason): never {
                        $this->activeRequestsCount--;
                        throw $reason instanceof \Throwable
                            ? $reason
                            : new ASKNetworkException((string)$reason);
                    }
                );
            };
        };
    }
}
