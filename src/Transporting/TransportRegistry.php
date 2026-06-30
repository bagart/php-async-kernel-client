<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Transporting;

use BAGArt\ASKClient\Contracts\Transporting\HttpTransportContract;
use BAGArt\ASKClient\Transporting\HttpTransports\ASKSocketTransport;
use BAGArt\ASKClient\Transporting\HttpTransports\CurlMultiTransport;
use BAGArt\ASKClient\Transporting\HttpTransports\GuzzleTransport;
use RuntimeException;

final class TransportRegistry
{
    /** @var array<string, class-string<HttpTransportContract>|HttpTransportContract> */
    private array $transports = [];

    /** @var array<string, class-string<HttpTransportContract>> */
    private static array $default = [
        CurlMultiTransport::TYPE => CurlMultiTransport::class,
        GuzzleTransport::TYPE => GuzzleTransport::class,
        ASKSocketTransport::TYPE => ASKSocketTransport::class,
    ];

    public static function build(): self
    {
        $registry = new self();

        foreach (self::$default as $type => $class) {
            $registry->register($class, $type);
        }

        return $registry;
    }

    /**
     * @param class-string<HttpTransportContract>|HttpTransportContract $transport
     */
    public function register(
        string|HttpTransportContract $transport,
        ?string $type = null,
    ): self {
        $this->transports[$type ?? $transport::TYPE] = $transport;

        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->transports[$type]);
    }

    /**
     * @return class-string<HttpTransportContract>|HttpTransportContract
     */
    public function get(string $type): string|HttpTransportContract
    {
        if (!$this->has($type)) {
            throw new RuntimeException("Transport type not registered: {$type}");
        }

        return $this->transports[$type];
    }

    public function make(string $type): HttpTransportContract
    {
        if (!$this->has($type)) {
            throw new RuntimeException("Transport type not registered: {$type}");
        }

        $transport = $this->transports[$type];

        if (is_object($transport)) {
            return $transport;
        }

        return match ($transport) {
            CurlMultiTransport::class => new CurlMultiTransport(),
            GuzzleTransport::class => new GuzzleTransport(),
            ASKSocketTransport::class => new ASKSocketTransport(),
            default => throw new RuntimeException("Unknown transport class: {$transport}"),
        };
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->transports);
    }

    public function __destruct()
    {
        $this->transports = [];
    }
}
