<?php

declare(strict_types=1);

namespace BAGArt\ASKClient;

use BAGArt\ASKClient\Contracts\ASKFutureContract;

final class ASKFuture implements ASKFutureContract
{
    private bool $resolved = false;

    private mixed $value = null;

    private ?\Throwable $error = null;

    private ?\Closure $producer = null;

    private function __construct()
    {
    }

    public static function resolved(mixed $value): self
    {
        $f = new self();
        $f->resolved = true;
        $f->value = $value;

        return $f;
    }

    public static function failed(\Throwable $error): self
    {
        $f = new self();
        $f->resolved = true;
        $f->error = $error;

        return $f;
    }

    public static function pending(callable $callback): self
    {
        $f = new self();
        $f->producer = $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback);

        return $f;
    }

    public function isCompleted(): bool
    {
        $this->resolveIfNeeded();

        return $this->resolved;
    }

    public function isSuccessful(): bool
    {
        $this->resolveIfNeeded();

        return $this->error === null;
    }

    public function getError(): ?\Throwable
    {
        $this->resolveIfNeeded();

        return $this->error;
    }

    public function await(): mixed
    {
        $this->resolveIfNeeded();

        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->value;
    }

    public function then(callable $callback): self
    {
        return self::pending(function () use ($callback): mixed {
            return $callback($this->await());
        });
    }

    public function catch(callable $callback): self
    {
        return self::pending(function () use ($callback): mixed {
            try {
                return $this->await();
            } catch (\Throwable $e) {
                return $callback($e);
            }
        });
    }

    public function recover(callable $callback): self
    {
        return $this->catch($callback);
    }

    public function finally(callable $callback): self
    {
        return self::pending(function () use ($callback): mixed {
            try {
                return $this->await();
            } finally {
                $callback();
            }
        });
    }

    private function resolveIfNeeded(): void
    {
        if ($this->resolved || $this->producer === null) {
            return;
        }

        $this->resolved = true;
        $producer = $this->producer;
        $this->producer = null;

        try {
            $this->value = $producer();
        } catch (\Throwable $e) {
            $this->error = $e;
        }
    }
}
