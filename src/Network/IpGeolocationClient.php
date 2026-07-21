<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Network;

use BAGArt\ASKClient\Contracts\Client\ApiClientContract;
use BAGArt\ASKClient\Contracts\Network\IpGeolocationContract;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\ASKClient\Response\ASKHttpResponse;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;

final class IpGeolocationClient implements IpGeolocationContract
{
    private const DEFAULT_API_URL = 'http://ip-api.com/json/';

    public function __construct(
        private readonly ApiClientContract $apiClient,
        private readonly string $apiUrl = self::DEFAULT_API_URL,
    ) {
    }

    public function resolveAsync(): ASKPromiseContract
    {
        $request = new ASKHttpRequest(
            url: $this->apiUrl,
            method: 'GET',
            requestName: 'ip-geolocation',
        );

        return $this->apiClient->requestAsync($request)->then(
            fn (mixed $response): IpGeolocation => $this->parseResponse($response),
        );
    }

    public function resolve(): IpGeolocation
    {
        $request = new ASKHttpRequest(
            url: $this->apiUrl,
            method: 'GET',
            requestName: 'ip-geolocation',
        );

        return $this->parseResponse($this->apiClient->request($request));
    }

    private function parseResponse(ASKHttpResponse $response): IpGeolocation
    {
        if ($response->getStatusCode() !== 200) {
            throw new ASKNetworkException(
                'Geolocation API returned HTTP '.$response->getStatusCode(),
            );
        }

        $body = (string) $response->getBody();

        try {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ASKNetworkException(
                message: 'Invalid JSON response from geolocation API',
                previous: $e,
            );
        }

        if (($data['status'] ?? null) === 'fail') {
            throw new ASKNetworkException(
                'Geolocation API error: '.($data['message'] ?? 'unknown error'),
            );
        }

        if (!isset($data['query'], $data['country'], $data['isp'])) {
            throw new ASKNetworkException(
                'Invalid geolocation response: missing required fields (ip, country, isp)',
            );
        }

        return new IpGeolocation(
            ip: $data['query'],
            country: $data['country'],
            isp: $data['isp'],
        );
    }
}
