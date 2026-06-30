<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Request;

final class ASKHttpRequest
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $queryParams
     * @param  array<int, mixed>  $curlOptions
     */
    public function __construct(
        public readonly string $url,
        public readonly string $method = 'GET',
        public array $headers = [],
        public ?string $body = null,
        public readonly array $queryParams = [],
        public array $curlOptions = [],
        public readonly string $requestName = 'unknown',
    ) {
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function fromParameters(
        string $url,
        string $method = 'POST',
        array $parameters = [],
        array $headers = [],
        array $curlOptions = [],
        string $requestName = 'unknown',
    ): self {
        $body = strtoupper($method) === 'GET'
            ? null
            : json_encode($parameters, JSON_THROW_ON_ERROR);

        return new self(
            url: $url,
            method: $method,
            headers: $headers,
            body: $body,
            queryParams: $parameters,
            curlOptions: $curlOptions,
            requestName: $requestName,
        );
    }

    public function withCurlOption(int $option, mixed $value): self
    {
        $curlOptions = $this->curlOptions;
        $curlOptions[$option] = $value;

        return new self(
            url: $this->url,
            method: $this->method,
            headers: $this->headers,
            body: $this->body,
            queryParams: $this->queryParams,
            curlOptions: $curlOptions,
            requestName: $this->requestName,
        );
    }

    public function getUrlWithQuery(): string
    {
        if ($this->queryParams === []) {
            return $this->url;
        }

        $query = http_build_query($this->queryParams);

        if ($query === '') {
            return $this->url;
        }

        return $this->url.(str_contains($this->url, '?') ? '&' : '?').$query;
    }

    /**
     * @return array<string, string>
     */
    public function formattedHeaders(): array
    {
        $result = [];
        foreach ($this->headers as $k => $v) {
            $result[] = $k.': '.$v;
        }

        return $result;
    }
}
