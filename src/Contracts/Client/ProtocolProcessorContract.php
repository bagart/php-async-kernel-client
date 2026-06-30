<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Contracts\Client;

use Psr\Http\Message\ResponseInterface;

interface ProtocolProcessorContract
{
    /**
     * Consume buffered inbound bytes and return a complete response once available.
     *
     * Implementations MAY mutate the buffer by reference, trimming consumed bytes.
     *
     * @param string $buffer inbound bytes accumulated by the transport
     */
    public function handleBuffer(string &$buffer): ?ResponseInterface;

    /**
     * Return pending outbound bytes the processor needs the transport to send
     * (e.g. HTTP/2 SETTINGS ACK, WINDOW_UPDATE, PING ACK) and clear the queue.
     *
     * Returns an empty string when there is nothing to send (e.g. HTTP/1.x).
     */
    public function drainOutbound(): string;
}
