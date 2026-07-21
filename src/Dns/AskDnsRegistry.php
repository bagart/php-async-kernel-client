<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns;

use BAGArt\ASKClient\Dns\Adapters\AmpDnsAdapter;
use BAGArt\ASKClient\Dns\Adapters\AsyncKernelDnsAdapter;
use BAGArt\ASKClient\Dns\Adapters\NativeDnsAdapter;
use BAGArt\ASKClient\Dns\Adapters\ReactDnsAdapter;
use BAGArt\ASKClient\Exceptions\AskConfigException;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use RuntimeException;

final class AskDnsRegistry
{
    public const string DEFAULT_ADAPTER = AsyncKernelDnsAdapter::TYPE;

    /** @var array<string, class-string<AskDnsAdapterContract>> */
    private static array $default = [
        AsyncKernelDnsAdapter::TYPE => AsyncKernelDnsAdapter::class,
        ReactDnsAdapter::TYPE => ReactDnsAdapter::class,
        AmpDnsAdapter::TYPE => AmpDnsAdapter::class,
        NativeDnsAdapter::TYPE => NativeDnsAdapter::class,
    ];

    /** @var array<string, class-string<AskDnsAdapterContract>> */
    private array $adapters = [];

    private ?ASKLogWrapper $logger = null;

    public static function build(?ASKLogWrapper $logger = null): self
    {
        $registry = new self();
        $registry->logger = $logger;
        foreach (self::$default as $type => $class) {
            $registry->register($class, $type);
        }
        return $registry;
    }

    public function register(string $adapterClass, ?string $type = null): self
    {
        $this->adapters[$type ?? $adapterClass::TYPE] = $adapterClass;
        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->adapters[$type]);
    }

    public function get(string $type): string
    {
        if (!$this->has($type)) {
            throw new RuntimeException("DNS adapter type not registered: {$type}");
        }
        return $this->adapters[$type];
    }

    public function make(string $type, AskDnsConfig $config): AskDnsAdapterContract
    {
        if (!$this->has($type)) {
            throw new AskConfigException("DNS adapter type not registered: {$type}");
        }

        $class = $this->adapters[$type];

        if (!class_exists($class)) {
            throw new AskConfigException("DNS adapter class {$class} not found. Is the library installed?");
        }

        if ($config->useTls() && !$class::TLS_SUPPORTED) {
            $this->logger?->warning(
                "DNS adapter '{$type}' does not support TLS. Falling back to UDP.",
            );
        }

        return match ($class) {
            AsyncKernelDnsAdapter::class => new AsyncKernelDnsAdapter($config),
            ReactDnsAdapter::class => new ReactDnsAdapter($config),
            AmpDnsAdapter::class => new AmpDnsAdapter($config),
            NativeDnsAdapter::class => new NativeDnsAdapter(),
            default => throw new AskConfigException("Unknown DNS adapter class: {$class}"),
        };
    }

    public function supportsTls(string $type): bool
    {
        $class = $this->adapters[$type] ?? null;

        return $class !== null && $class::TLS_SUPPORTED;
    }

    public function types(): array
    {
        return array_keys($this->adapters);
    }

    public function __destruct()
    {
        $this->adapters = [];
    }
}
