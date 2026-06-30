<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Response;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * PSR-7 Compliant Inbound HTTP Response.
 * Implements immutable ResponseInterface optimized for high-load async runtimes.
 */
final class ASKHttpResponse implements ResponseInterface
{
    private string $protocolVersion;
    private int $statusCode;
    private string $reasonPhrase;
    /** @var array<string, list<string>> */
    private array $headerMap = [];
    /** @var array<string, string> */
    private array $headerNames = [];
    private StreamInterface $bodyStream;

    public function __construct(
        string $protocolVersion,
        int $statusCode,
        string $reasonPhrase,
        array $headers,
        StreamInterface $body
    ) {
        $this->protocolVersion = $protocolVersion;
        $this->statusCode = $statusCode;
        $this->reasonPhrase = $reasonPhrase;
        $this->bodyStream = $body;

        foreach ($headers as $name => $value) {
            $lower = \strtolower($name);
            $this->headerNames[$lower] = $name;
            $this->headerMap[$lower] = \is_array($value) ? \array_values($value) : [(string)$value];
        }
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): self
    {
        $new = clone $this;
        $new->protocolVersion = $version;
        return $new;
    }

    public function getHeaders(): array
    {
        $result = [];
        foreach ($this->headerMap as $l => $v) {
            $result[$this->headerNames[$l]] = $v;
        }
        return $result;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerMap[\strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        return $this->headerMap[\strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return \implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): self
    {
        $new = clone $this;
        $lower = \strtolower($name);
        $new->headerNames[$lower] = $name;
        $new->headerMap[$lower] = \is_array($value) ? \array_values($value) : [(string)$value];
        return $new;
    }

    public function withAddedHeader(string $name, $value): self
    {
        $new = clone $this;
        $lower = \strtolower($name);
        if (!isset($new->headerNames[$lower])) {
            $new->headerNames[$lower] = $name;
        }
        $values = \is_array($value) ? \array_values($value) : [(string)$value];
        $new->headerMap[$lower] = \array_merge($new->headerMap[$lower] ?? [], $values);
        return $new;
    }

    public function withoutHeader(string $name): self
    {
        $new = clone $this;
        $lower = \strtolower($name);
        unset($new->headerMap[$lower], $new->headerNames[$lower]);
        return $new;
    }

    public function getBody(): StreamInterface
    {
        return $this->bodyStream;
    }

    public function withBody(StreamInterface $body): self
    {
        $new = clone $this;
        $new->bodyStream = $body;
        return $new;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): self
    {
        $new = clone $this;
        $new->statusCode = $code;
        $new->reasonPhrase = $reasonPhrase;
        return $new;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }
}
