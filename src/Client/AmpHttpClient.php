<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as AmpRequest;
use BAGArt\ASKClient\Contracts\Client\NetworkClientContract;
use BAGArt\ASKClient\Drivers\AmpNetworkTickableDriver;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\ASKClient\Response\ASKHttpResponse;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Promise\ASKPromise;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

final class AmpHttpClient implements NetworkClientContract
{
    private readonly HttpClient $client;
    private readonly AmpNetworkTickableDriver $tickableDriver;

    public function __construct(
        ?HttpClient $client = null,
    ) {
        $this->client = $client ?? HttpClientBuilder::build();
        $this->tickableDriver = new AmpNetworkTickableDriver();
    }

    public function request(ASKHttpRequest $request): ASKPromiseContract
    {
        $ampRequest = new AmpRequest(
            $request->getUrlWithQuery(),
            $request->method,
            $request->body ?? '',
        );

        foreach ($request->headers as $name => $value) {
            $ampRequest->setHeader($name, $value);
        }

        $promise = new ASKPromise($this->tickableDriver);

        \Amp\async(function () use ($ampRequest, $promise): void {
            try {
                $response = $this->client->request($ampRequest);
                $promise->resolve($this->toAskResponse($response));
            } catch (\Throwable $e) {
                $promise->reject(new ASKNetworkException(
                    message: $e->getMessage(),
                    code: (int)$e->getCode(),
                    previous: $e,
                ));
            }
        });

        return $promise;
    }

    public function tickable(): array
    {
        return [$this->tickableDriver];
    }

    private function toAskResponse(ResponseInterface $response): ASKHttpResponse
    {
        $bodyContents = $response->getBody()->__toString();
        $body = Utils::streamFor($bodyContents);

        return new ASKHttpResponse(
            protocolVersion: $response->getProtocolVersion(),
            statusCode: $response->getStatusCode(),
            reasonPhrase: $response->getReasonPhrase(),
            headers: $response->getHeaders(),
            body: $body,
        );
    }
}
