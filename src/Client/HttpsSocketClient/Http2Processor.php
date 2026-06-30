<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client\HttpsSocketClient;

use BAGArt\ASKClient\Contracts\Client\ProtocolProcessorContract;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\ASKClient\Response\ASKHttpResponse;
use Psr\Http\Message\ResponseInterface;

final class Http2Processor implements ProtocolProcessorContract
{
    private Http2HpackDecoder $hpack;
    private array $headersBuffer = [];
    private array $dataBuffer = [];
    private int $maxFrameSize = 16384;

    public function __construct()
    {
        $this->hpack = new Http2HpackDecoder();
    }

    public function drainOutbound(): string
    {
        // HTTP/2 single-request mode relies on server-side flow-control defaults;
        // no outbound control frames are queued here.
        return '';
    }

    public function handleBuffer(string &$buffer): ?ResponseInterface
    {
        while (\strlen($buffer) >= 9) {
            $unpack = \unpack('C3l/Ct/Cf/Nid', \substr($buffer, 0, 9));
            if (!$unpack) {
                return null;
            }

            $len = ($unpack['l1'] << 16) | ($unpack['l2'] << 8) | $unpack['l3'];
            if ($len > $this->maxFrameSize) {
                throw new ASKNetworkException(
                    'Protocol error: FRAME_SIZE_ERROR'
                );
            }

            if (\strlen($buffer) < 9 + $len) {
                return null;
            }

            $type = $unpack['t'];
            $flags = $unpack['f'];
            $streamId = $unpack['id'] & 0x7FFFFFFF;
            $payload = \substr($buffer, 9, $len);
            $buffer = \substr($buffer, 9 + $len);

            switch ($type) {
                case 0x01: // HEADERS
                case 0x09: // CONTINUATION
                    $this->headersBuffer[$streamId] = ($this->headersBuffer[$streamId] ?? '').$payload;
                    if (($flags & 0x04) !== 0) { // END_HEADERS
                        if (($flags & 0x01) !== 0) { // END_STREAM
                            return $this->buildResponse($streamId);
                        }
                    }
                    break;

                case 0x00: // DATA
                    $this->dataBuffer[$streamId] = ($this->dataBuffer[$streamId] ?? '').$payload;
                    if (($flags & 0x01) !== 0) { // END_STREAM
                        return $this->buildResponse($streamId);
                    }
                    break;
            }
        }
        return null;
    }

    private function buildResponse(int $streamId): ResponseInterface
    {
        $rawHeaders = $this->headersBuffer[$streamId] ?? '';
        $rawBody = $this->dataBuffer[$streamId] ?? '';
        unset($this->headersBuffer[$streamId], $this->dataBuffer[$streamId]);

        $headers = $this->hpack->decodeBlock($rawHeaders);
        $status = (int)($headers[':status'] ?? 200);

        return new ASKHttpResponse('2.0', $status, 'OK', $headers, MemoryStreamFactory::createFromString($rawBody));
    }
}
