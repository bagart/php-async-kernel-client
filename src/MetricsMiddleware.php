<?php

declare(strict_types=1);

namespace BAGArt\ASKClient;

use BAGArt\ASKClient\Contracts\ASKContextContract;
use BAGArt\ASKClient\Contracts\ASKFutureContract;
use BAGArt\ASKClient\Contracts\ASKHandlerContract;

/**
 * Records timing and outcome of each operation without blocking the chain.
 *
 * Attaches completion callbacks to the future returned by $next and returns
 * the derived future immediately — it never awaits inside the handler. A
 * throwing sink is swallowed so observability never breaks the operation.
 */
final class MetricsMiddleware implements ASKHandlerContract
{
    /**
     * @param  callable(array<string, mixed>): void $sink called once per operation when it settles
     */
    public function __construct(
        private readonly \Closure $sink,
    ) {
    }

    public function __invoke(
        object $operation,
        ASKContextContract $context,
        ASKNextHandler $next,
    ): ASKFutureContract {
        $start = microtime(true);
        $operationClass = $operation::class;
        $node = $context->get(ClusterMiddleware::NODE_KEY);

        $future = $next($operation, $context);

        return $future
            ->then(function (mixed $result) use ($start, $operationClass, $node): mixed {
                $this->emit($this->record($operationClass, $node, $start, 'success', null));

                return $result;
            })
            ->catch(function (\Throwable $error) use ($start, $operationClass, $node): \Throwable {
                $this->emit($this->record($operationClass, $node, $start, 'failure', $error));

                throw $error;
            });
    }

    private function emit(array $record): void
    {
        try {
            ($this->sink)($record);
        } catch (\Throwable) {
            // Observability must never break execution.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function record(
        string $operation,
        mixed $node,
        float $start,
        string $outcome,
        ?\Throwable $error,
    ): array {
        return [
            'operation' => $operation,
            'node' => $node,
            'outcome' => $outcome,
            'duration_ms' => (microtime(true) - $start) * 1_000,
            'error' => $error?->getMessage(),
        ];
    }
}
