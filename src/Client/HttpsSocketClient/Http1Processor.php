<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client\HttpsSocketClient;

use BAGArt\ASKClient\Contracts\Client\ProtocolProcessorContract;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\ASKClient\Response\ASKHttpResponse;
use Psr\Http\Message\ResponseInterface;

final class Http1Processor implements ProtocolProcessorContract
{
    private bool $headersParsed = false;
    private int $contentLength = -1;
    private bool $isChunked = false;
    private string $bodyAccumulator = '';

    // Typed state buffer for current response metadata
    private string $currentProtocol = '1.1';
    private int $currentStatus = 200;
    private string $currentReason = 'OK';
    private array $currentHeaders = [];

    public function handleBuffer(string &$buffer): ?ResponseInterface
    {
        return $this->processResponse($buffer);
    }

    public function drainOutbound(): string
    {
        // HTTP/1.x has no protocol-level control frames; nothing to emit.
        return '';
    }

    private function processResponse(string &$buffer): ?ResponseInterface
    {
        if (!$this->headersParsed) {
            $separator = \strpos($buffer, "\r\n\r\n");
            if ($separator === false) {
                return null;
            }

            $headerBlock = \substr($buffer, 0, $separator);
            $buffer = \substr($buffer, $separator + 4);

            $firstCrlf = \strpos($headerBlock, "\r\n");
            if ($firstCrlf === false) {
                throw new ASKNetworkException(
                    'Invalid HTTP protocol structure'
                );
            }

            $statusLine = \substr($headerBlock, 0, $firstCrlf);
            if (!\preg_match('#^HTTP/(\d+\.\d+)\s+(\d{3})\s*(.*)$#', $statusLine, $matches)) {
                throw new ASKNetworkException(
                    'Malformed HTTP status line'
                );
            }

            $this->currentProtocol = $matches[1];
            $this->currentStatus = (int)$matches[2];
            $this->currentReason = $matches[3];

            $headerLines = \substr($headerBlock, $firstCrlf + 2);
            $this->currentHeaders = [];
            foreach (\explode("\r\n", $headerLines) as $line) {
                $pos = \strpos($line, ':');
                if ($pos !== false) {
                    $this->currentHeaders[\trim(\substr($line, 0, $pos))][] = \trim(\substr($line, $pos + 1));
                }
            }

            $this->isChunked = \strtolower($this->currentHeaders['Transfer-Encoding'][0] ?? '') === 'chunked';
            $this->contentLength = isset($this->currentHeaders['Content-Length'][0]) ? (int)$this->currentHeaders['Content-Length'][0] : -1;
            $this->headersParsed = true;
        }

        if ($this->isChunked) {
            return $this->parseChunkedStream($buffer);
        }

        if ($this->contentLength !== -1) {
            if (\strlen($buffer) < $this->contentLength) {
                return null; // Wait for body to load
            }
            $body = \substr($buffer, 0, $this->contentLength);
            $buffer = \substr($buffer, $this->contentLength);
            return $this->emitResponse($body);
        }

        return $this->emitResponse($buffer);
    }

    private function parseChunkedStream(string &$buffer): ?ResponseInterface
    {
        while (($crlf = \strpos($buffer, "\r\n")) !== false) {
            $hex = \substr($buffer, 0, $crlf);
            $chunkSize = \hexdec(\trim($hex));

            if ($chunkSize === 0) {
                $buffer = \substr($buffer, $crlf + 4); // Remove socket terminator
                return $this->emitResponse($this->bodyAccumulator);
            }

            if (\strlen($buffer) < $crlf + 2 + $chunkSize + 2) {
                return null;
            }

            $this->bodyAccumulator .= \substr($buffer, $crlf + 2, $chunkSize);
            $buffer = \substr($buffer, $crlf + 2 + $chunkSize + 2);
        }
        return null;
    }

    private function emitResponse(string $body): ResponseInterface
    {
        $response = new ASKHttpResponse(
            $this->currentProtocol,
            $this->currentStatus,
            $this->currentReason,
            $this->currentHeaders,
            MemoryStreamFactory::createFromString($body)
        );

        // Full state reset for processor reuse in Event Loop
        $this->headersParsed = false;
        $this->bodyAccumulator = '';
        $this->currentHeaders = [];

        return $response;
    }
}
