<?php

declare(strict_types=1);

namespace BAGArt\ASKClient;

use BAGArt\ASKClient\Contracts\ASKContextContract;
use BAGArt\ASKClient\Contracts\ASKFutureContract;
use BAGArt\ASKClient\Contracts\ASKHandlerContract;
use BAGArt\ASKClient\Contracts\Client\ASKClientContract;
use BAGArt\ASKClient\Contracts\Transporting\ASKTransportContract;

final class ASKClient implements ASKClientContract
{
    /** @var list<ASKHandlerContract> */
    private readonly array $handlers;

    /**
     * @param  ASKHandlerContract[]  $handlers
     */
    public function __construct(
        private readonly ASKTransportContract $transport,
        array $handlers = [],
    ) {
        $this->handlers = array_values($handlers);
    }

    public function execute(object $operation, ?ASKContextContract $context = null): ASKFutureContract
    {
        $context ??= ASKContext::empty();

        $handler = ASKNextHandler::wrap(
            fn (object $op, ASKContextContract $ctx): ASKFutureContract =>
                $this->transport->execute($op, $ctx),
        );

        foreach (array_reverse($this->handlers) as $h) {
            $handler = ASKNextHandler::wrap(
                fn (object $op, ASKContextContract $ctx): ASKFutureContract =>
                    $h($op, $ctx, $handler),
            );
        }

        return $handler($operation, $context);
    }
}
