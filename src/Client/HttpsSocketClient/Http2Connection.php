<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client\HttpsSocketClient;

use BAGArt\ASKClient\Contracts\Client\ProtocolProcessorContract;
use BAGArt\ASKClient\Response\ASKHttpResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Manages a single HTTP/2 connection state machine.
 *
 * Implements {@see ProtocolProcessorContract} so it can be used as a drop-in
 * processor on a {@see PooledConnection}, while exposing the underlying
 * state-machine API (handshake, request building, control-frame handling)
 * for the transport layer.
 */
final class Http2Connection implements ProtocolProcessorContract
{
    private const int DEFAULT_MAX_CONCURRENT_STREAMS = 100;

    private string $readBuffer = '';

    /**
     * @var array<int, array{headers: array<string, string>, body: string, done: bool}>
     */
    private array $streams = [];

    private int $nextStreamId = 1;

    private int $maxConcurrentStreams = self::DEFAULT_MAX_CONCURRENT_STREAMS;

    /**
     * @var list<array{streamId: int, frameData: string}>
     */
    private array $pendingWrites = [];

    private readonly Http2HpackDecoder $hpack;

    private bool $prefaceSent = false;

    private bool $settingsReceived = false;

    public function __construct()
    {
        $this->hpack = new Http2HpackDecoder();
    }

    public function getInitialFrames(): string
    {
        if ($this->prefaceSent) {
            return '';
        }

        $this->prefaceSent = true;

        return Frame::CONNECTION_PREFACE
            .Frame::encodeSettings([
                Frame::SETTINGS_MAX_CONCURRENT_STREAMS => self::DEFAULT_MAX_CONCURRENT_STREAMS,
                Frame::SETTINGS_INITIAL_WINDOW_SIZE => Frame::INITIAL_WINDOW_SIZE,
                Frame::SETTINGS_MAX_FRAME_SIZE => 16384,
            ]);
    }

    public function feed(string $data): array
    {
        $this->readBuffer .= $data;
        $completed = [];

        while (true) {
            $result = Frame::parse($this->readBuffer);

            if ($result === null) {
                break;
            }

            [$frame, $consumed] = $result;
            // Fix potential memory leak: remove processed data from buffer
            $this->readBuffer = (string)\substr($this->readBuffer, $consumed);

            $streamCompleted = $this->handleFrame($frame);

            if ($streamCompleted !== null) {
                $completed[] = $streamCompleted;
            }
        }

        return $completed;
    }

    /**
     * {@see ProtocolProcessorContract} adapter for the single-request model:
     * feeds inbound bytes into the state machine and resolves the first
     * completed stream as the response. Outbound control frames queued during
     * processing (SETTINGS ACK, WINDOW_UPDATE, PING ACK) become available via
     * {@see drainOutbound()}.
     *
     * The buffer is cleared: inbound bytes are absorbed into the internal
     * read buffer, so the transport can reuse a fresh buffer next cycle.
     */
    public function handleBuffer(string &$buffer): ?ResponseInterface
    {
        $completed = $this->feed($buffer);
        $buffer = '';

        foreach ($completed as $streamId) {
            return $this->buildResponse($streamId);
        }

        foreach ($this->streams as $streamId => $stream) {
            if ($stream['done']) {
                return $this->buildResponse($streamId);
            }
        }

        return null;
    }

    /**
     * Drain outbound control frames queued by the state machine and return
     * them as a single binary string ready for the transport to write.
     */
    public function drainOutbound(): string
    {
        return \implode('', $this->drainPendingWrites());
    }

    private function handleFrame(Frame $frame): ?int
    {
        return match ($frame->type) {
            Frame::TYPE_SETTINGS => $this->handleSettings($frame),
            Frame::TYPE_HEADERS => $this->handleHeaders($frame),
            Frame::TYPE_DATA => $this->handleData($frame),
            Frame::TYPE_CONTINUATION => $this->handleContinuation($frame),
            Frame::TYPE_WINDOW_UPDATE => null,
            Frame::TYPE_RST_STREAM => $this->handleRstStream($frame),
            Frame::TYPE_PING => $this->handlePing($frame),
            Frame::TYPE_GOAWAY => $this->handleGoaway($frame),
            default => null,
        };
    }

    private function handleSettings(Frame $frame): ?int
    {
        if (($frame->flags & Frame::FLAG_ACK) !== 0) {
            return null;
        }

        $payload = $frame->payload;

        for ($i = 0, $loopsMax = \strlen($payload); $i + 6 <= $loopsMax; $i += 6) {
            $id = (\ord($payload[$i]) << 8) | \ord($payload[$i + 1]);
            $value = Frame::decodeInt(\substr($payload, $i + 2, 4));

            match ($id) {
                Frame::SETTINGS_MAX_CONCURRENT_STREAMS => $this->maxConcurrentStreams = $value,
                default => null,
            };
        }

        $this->settingsReceived = true;

        $this->pendingWrites[] = [
            'streamId' => 0,
            'frameData' => Frame::encodeSettingsAck(),
        ];

        return null;
    }

    private function handleHeaders(Frame $frame): ?int
    {
        $streamId = $frame->streamId;

        if ($streamId === 0) {
            return null;
        }

        $payload = $frame->payload;

        if (($frame->flags & Frame::FLAG_PADDED) !== 0) {
            $padLength = \ord($payload[0]);
            $payload = \substr($payload, 1, \strlen($payload) - 1 - $padLength);
        }

        if (($frame->flags & Frame::FLAG_PRIORITY) !== 0) {
            $payload = \substr($payload, 5);
        }

        $headers = $this->hpack->decodeBlock($payload);
        $endHeaders = ($frame->flags & Frame::FLAG_END_HEADERS) !== 0;
        $endStream = ($frame->flags & Frame::FLAG_END_STREAM) !== 0;

        if (!isset($this->streams[$streamId])) {
            $this->streams[$streamId] = [
                'headers' => $headers,
                'body' => '',
                'done' => $endStream,
            ];
        } else {
            $this->streams[$streamId]['headers'] = \array_merge(
                $this->streams[$streamId]['headers'],
                $headers
            );
            $this->streams[$streamId]['done'] = $endStream;
        }

        // Extend flow control window
        $this->pendingWrites[] = [
            'streamId' => $streamId,
            'frameData' => Frame::encodeWindowUpdate($streamId, Frame::INITIAL_WINDOW_SIZE),
        ];

        if ($endHeaders && !$endStream) {
            return null;
        }

        if ($endStream && $this->streams[$streamId]['done']) {
            return $streamId;
        }

        return null;
    }

    private function handleContinuation(Frame $frame): ?int
    {
        return $this->handleHeaders($frame);
    }

    private function handleData(Frame $frame): ?int
    {
        $streamId = $frame->streamId;

        if ($streamId === 0 || !isset($this->streams[$streamId])) {
            return null;
        }

        $payload = $frame->payload;

        if (($frame->flags & Frame::FLAG_PADDED) !== 0) {
            $padLength = \ord($payload[0]);
            $payload = \substr($payload, 1, \strlen($payload) - 1 - $padLength);
        }

        $this->streams[$streamId]['body'] .= $payload;
        $dataLen = \strlen($payload);

        if ($dataLen > 0) {
            // Important: update global connection window (0) so the server does not hit the limit
            $this->pendingWrites[] = [
                'streamId' => 0,
                'frameData' => Frame::encodeWindowUpdate(0, $dataLen),
            ];
        }

        if (($frame->flags & Frame::FLAG_END_STREAM) !== 0) {
            $this->streams[$streamId]['done'] = true;
            return $streamId;
        }

        return null;
    }

    private function handleRstStream(Frame $frame): ?int
    {
        $streamId = $frame->streamId;

        if (isset($this->streams[$streamId])) {
            $this->streams[$streamId]['done'] = true;
        }

        return $streamId;
    }

    private function handlePing(Frame $frame): ?int
    {
        if (($frame->flags & Frame::FLAG_ACK) === 0) {
            $this->pendingWrites[] = [
                'streamId' => 0,
                'frameData' => \chr(0).\chr(0).\chr(8).\chr(Frame::TYPE_PING)
                    .\chr(Frame::FLAG_ACK)."\0\0\0\0"
                    .$frame->payload,
            ];
        }

        return null;
    }

    private function handleGoaway(Frame $frame): ?int
    {
        foreach ($this->streams as $id => &$stream) {
            $stream['done'] = true;
        }
        return null;
    }

    /**
     * @param array<string, string|list<string>> $extraHeaders
     */
    public function buildRequest(string $method, string $host, string $path, string $body, array $extraHeaders = []): string
    {
        $streamId = $this->nextStreamId;
        $this->nextStreamId += 2;

        $this->streams[$streamId] = [
            'headers' => [],
            'body' => '',
            'done' => false,
        ];

        $appendHeader = static function (string &$block, string $name, string $value): void {
            // Literal-without-indexing (HPACK): 0x00 prefix + length-prefixed name/value.
            $block .= "\x00".\chr(\strlen($name)).$name.\chr(\strlen($value)).$value;
        };

        $headerBlock = '';
        $appendHeader($headerBlock, ':method', $method);
        $appendHeader($headerBlock, ':path', $path);
        $appendHeader($headerBlock, ':scheme', 'https');
        $appendHeader($headerBlock, ':authority', $host);

        foreach ($extraHeaders as $name => $value) {
            foreach ((array)$value as $v) {
                $appendHeader($headerBlock, $name, (string)$v);
            }
        }

        $endStream = $body === '';
        $result = Frame::encodeHeaders($streamId, $headerBlock, $endStream);

        if ($body !== '') {
            $result .= Frame::encodeData($streamId, $body, true);
        }

        foreach ($this->pendingWrites as $write) {
            $result .= $write['frameData'];
        }

        $this->pendingWrites = [];

        return $result;
    }

    public function buildResponse(int $streamId): ?ASKHttpResponse
    {
        if (!isset($this->streams[$streamId]) || !$this->streams[$streamId]['done']) {
            return null;
        }

        $stream = $this->streams[$streamId];
        $headers = $stream['headers'];
        $body = $stream['body'];

        $statusCode = 200;
        $reasonPhrase = 'OK';
        $responseHeaders = [];

        foreach ($headers as $name => $value) {
            if ($name === ':status') {
                $statusCode = (int)$value;
                $reasonPhrase = self::statusReason($statusCode);
            } elseif (\str_starts_with($name, ':')) {
                continue;
            } else {
                $responseHeaders[$name][] = $value;
            }
        }

        $flatHeaders = [];
        foreach ($responseHeaders as $name => $values) {
            $flatHeaders[$name] = \count($values) === 1 ? $values[0] : $values;
        }

        unset($this->streams[$streamId]);

        if ($body !== '') {
            $contentEncoding = '';
            foreach ($headers as $name => $value) {
                if (\strtolower($name) === 'content-encoding') {
                    $contentEncoding = \strtolower($value);
                }
            }

            if ($contentEncoding === 'gzip' || $contentEncoding === 'deflate') {
                $decoded = @\zlib_decode($body);
                if ($decoded !== false) {
                    $body = $decoded;
                }
            }
        }

        return new ASKHttpResponse(
            protocolVersion: '2',
            statusCode: $statusCode,
            reasonPhrase: $reasonPhrase,
            headers: $flatHeaders,
            body: MemoryStreamFactory::createFromString($body),
        );
    }

    public function hasPendingWrites(): bool
    {
        return $this->pendingWrites !== [];
    }

    public function drainPendingWrites(): array
    {
        $writes = [];
        foreach ($this->pendingWrites as $w) {
            $writes[] = $w['frameData'];
        }
        $this->pendingWrites = [];
        return $writes;
    }

    public function isReady(): bool
    {
        return $this->settingsReceived;
    }

    private static function statusReason(int $code): string
    {
        return match ($code) {
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'Unknown',
        };
    }
}
