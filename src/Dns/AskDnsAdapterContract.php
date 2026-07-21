<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns;

interface AskDnsAdapterContract
{
    public const string TYPE = '';
    public const bool TLS_SUPPORTED = false;

    public function resolve(string $host): ?string;

    public function tick(): void;

    public function getReadSockets(): array;

    public function processReadable(mixed $socket): bool;

    public function flushFresh(): array;

    public static function clearCache(): void;

    public function warmUp(string $host): ?string;
}
