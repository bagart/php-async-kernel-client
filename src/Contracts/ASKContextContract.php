<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts;

interface ASKContextContract
{
    public function with(string $key, mixed $value): self;

    public function without(string $key): self;

    public function merge(self $other): self;

    public function get(string $key, mixed $default = null): mixed;

    public function has(string $key): bool;

    public function all(): array;
}
