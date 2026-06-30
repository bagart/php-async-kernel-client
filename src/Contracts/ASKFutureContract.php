<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts;

interface ASKFutureContract
{
    public function isCompleted(): bool;

    public function isSuccessful(): bool;

    public function getError(): ?\Throwable;

    public function await(): mixed;

    public function then(callable $callback): self;

    public function catch(callable $callback): self;

    public function recover(callable $callback): self;

    public function finally(callable $callback): self;
}
