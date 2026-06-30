<?php

declare(strict_types=1);

namespace BAGArt\ASKClient;

use BAGArt\ASKClient\Contracts\ASKContextContract;

final class ASKContext implements ASKContextContract
{
    /** @var array<string, mixed> */
    private array $data = [];

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function from(array $data): self
    {
        $ctx = new self();
        $ctx->data = $data;

        return $ctx;
    }

    public function with(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->data[$key] = $value;

        return $clone;
    }

    public function without(string $key): self
    {
        $clone = clone $this;
        unset($clone->data[$key]);

        return $clone;
    }

    public function merge(ASKContextContract $other): self
    {
        $clone = clone $this;
        $clone->data = array_merge($this->data, $other->all());

        return $clone;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }
}
