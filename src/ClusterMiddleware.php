<?php

declare(strict_types=1);

namespace BAGArt\ASKClient;

use BAGArt\ASKClient\Contracts\ASKContextContract;
use BAGArt\ASKClient\Contracts\ASKFutureContract;
use BAGArt\ASKClient\Contracts\ASKHandlerContract;

/**
 * Routes an operation to a cluster node before delegating down the chain.
 *
 * The transport is the single terminal of the chain and the library is
 * transport-agnostic, so routing is emitted as a context hint
 * ("cluster.node") that the application transport honours — the handler
 * never bypasses $next.
 */
final class ClusterMiddleware implements ASKHandlerContract
{
    public const string NODE_KEY = 'cluster.node';

    /** @var list<string> */
    private readonly array $nodes;

    private int $cursor = 0;

    /**
     * @param  string[]                                   $nodes  non-empty list of cluster node identifiers
     * @param  callable(object, ASKContextContract): string $router returns the node for this call
     */
    public function __construct(
        array $nodes,
        private readonly ?\Closure $router = null,
    ) {
        if ($nodes === []) {
            throw new \InvalidArgumentException('ClusterMiddleware requires at least one node.');
        }

        $this->nodes = array_values($nodes);
    }

    public function __invoke(
        object $operation,
        ASKContextContract $context,
        ASKNextHandler $next,
    ): ASKFutureContract {
        $node = $this->resolveNode($operation, $context);

        return $next($operation, $context->with(self::NODE_KEY, $node));
    }

    private function resolveNode(object $operation, ASKContextContract $context): string
    {
        if ($this->router !== null) {
            return ($this->router)($operation, $context);
        }

        $node = $this->nodes[$this->cursor % count($this->nodes)];
        $this->cursor++;

        return $node;
    }
}
