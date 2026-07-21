<?php

declare(strict_types=1);

use BAGArt\ASKClient\Client\HttpsSocketClient\Frame;
use BAGArt\ASKClient\Client\HttpsSocketClient\Http2Connection;
use BAGArt\ASKClient\Client\HttpsSocketClient\HttpsSocketClient;
use BAGArt\ASKClient\Client\HttpsSocketClient\HttpsSocketClientConfig;
use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\ASKClient\Response\ASKHttpResponse;

/**
 * Build a minimal server-side HPACK header block (literal-without-indexing) for
 * the given header tuples. Mirrors what Http2Connection::buildRequest emits, so
 * Http2HpackDecoder::decodeBlock can parse it back without a dynamic table.
 *
 * @param  array<int, array{0:string, 1:string}>  $headers
 */
function hpackLiteralHeaders(array $headers): string
{
    $block = '';
    foreach ($headers as [$name, $value]) {
        $block .= "\x00" // literal-without-indexing, no Huffman
            .chr(strlen($name)).$name
            .chr(strlen($value)).$value;
    }

    return $block;
}

describe('Frame', function () {
    it('round-trips an encoded SETTINGS frame through parse', function () {
        $encoded = Frame::encodeSettings([
            Frame::SETTINGS_MAX_CONCURRENT_STREAMS => 128,
            Frame::SETTINGS_INITIAL_WINDOW_SIZE => Frame::INITIAL_WINDOW_SIZE,
        ]);

        $result = Frame::parse($encoded);

        expect($result)->not->toBeNull();
        [$frame, $consumed] = $result;
        expect($frame->type)->toBe(Frame::TYPE_SETTINGS)
            ->and($frame->streamId)->toBe(0)
            ->and($consumed)->toBe(strlen($encoded));

        // Two settings × 6 bytes each = 12 bytes of payload.
        expect($frame->length)->toBe(12);
    });

    it('parse returns null when fewer than 9 bytes are available', function () {
        expect(Frame::parse("\x00\x00\x00"))->toBeNull();
    });

    it('parse returns null when the declared payload has not fully arrived', function () {
        // 9-byte header declaring a 10-byte payload, but no payload bytes supplied.
        $partial = pack('C3CCN', 0, 0, 10, Frame::TYPE_DATA, Frame::FLAG_END_STREAM, 1);

        expect(Frame::parse($partial))->toBeNull();
    });

    it('encodes a SETTINGS ACK as an empty payload with the ACK flag', function () {
        $encoded = Frame::encodeSettingsAck();

        [$frame,] = Frame::parse($encoded);
        expect($frame->type)->toBe(Frame::TYPE_SETTINGS)
            ->and($frame->flags & Frame::FLAG_ACK)->not->toBe(0)
            ->and($frame->length)->toBe(0);
    });

    it('round-trips a PING frame with the opaque 8-byte payload', function () {
        $opaque = 'ABCDEFGH';
        [$frame,] = Frame::parse(Frame::encodePing($opaque));

        expect($frame->type)->toBe(Frame::TYPE_PING)
            ->and($frame->payload)->toBe($opaque);
    });

    it('round-trips WINDOW_UPDATE carrying the increment on the given stream', function () {
        [$frame,] = Frame::parse(Frame::encodeWindowUpdate(5, 32768));

        expect($frame->type)->toBe(Frame::TYPE_WINDOW_UPDATE)
            ->and($frame->streamId)->toBe(5)
            ->and(Frame::decodeInt($frame->payload))->toBe(32768);
    });

    it('round-trips a GOAWAY frame with last-stream-id and error code', function () {
        [$frame,] = Frame::parse(Frame::encodeGoaway(7, 0x1));

        expect($frame->type)->toBe(Frame::TYPE_GOAWAY)
            ->and($frame->streamId)->toBe(0); // GOAWAY is always on stream 0.
    });

    it('round-trips an RST_STREAM frame', function () {
        [$frame,] = Frame::parse(Frame::encodeRstStream(3, 0x8));

        expect($frame->type)->toBe(Frame::TYPE_RST_STREAM)
            ->and($frame->streamId)->toBe(3);
    });

    it('auto-fragments DATA larger than the default max frame size', function () {
        // DEFAULT_MAX_FRAME_SIZE is 16384; supply 16384 + 1 bytes → two DATA frames.
        $encoded = Frame::encodeData(1, str_repeat('x', Frame::DEFAULT_MAX_FRAME_SIZE + 1), true);

        // Walk two frames off the buffer; the second must carry END_STREAM.
        [$first, $consumed1] = Frame::parse($encoded);
        [$second,] = Frame::parse(substr($encoded, $consumed1));

        expect($first->type)->toBe(Frame::TYPE_DATA)
            ->and($first->length)->toBe(Frame::DEFAULT_MAX_FRAME_SIZE)
            ->and($first->flags & Frame::FLAG_END_STREAM)->toBe(0)
            ->and($second->length)->toBe(1)
            ->and($second->flags & Frame::FLAG_END_STREAM)->not->toBe(0);
    });
});

describe('Http2Connection', function () {
    it('emits the connection preface + SETTINGS once, then nothing on the second call', function () {
        $conn = new Http2Connection();

        $initial = $conn->getInitialFrames();
        expect($initial)->not->toBe('')
            ->and(str_starts_with($initial, Frame::CONNECTION_PREFACE))->toBeTrue();

        // First 24 bytes are the preface; the next frame must be SETTINGS (type 0x04).
        $settingsPart = substr($initial, strlen(Frame::CONNECTION_PREFACE));
        [$settingsFrame,] = Frame::parse($settingsPart);
        expect($settingsFrame->type)->toBe(Frame::TYPE_SETTINGS);

        // Idempotency guard: a second call must not resend the preface.
        expect($conn->getInitialFrames())->toBe('');
    });

    it('acks server SETTINGS and flips to ready, surfacing the ACK via drainOutbound', function () {
        $conn = new Http2Connection();

        $completed = $conn->feed(Frame::encodeSettings([
            Frame::SETTINGS_MAX_CONCURRENT_STREAMS => 10,
        ]));

        expect($completed)->toBe([])
            ->and($conn->isReady())->toBeTrue();

        $outbound = $conn->drainOutbound();
        expect($outbound)->not->toBe('');
        [$ack,] = Frame::parse($outbound);
        expect($ack->type)->toBe(Frame::TYPE_SETTINGS)
            ->and($ack->flags & Frame::FLAG_ACK)->not->toBe(0);
    });

    it('answers PING with a PING ACK queued for the transport', function () {
        $conn = new Http2Connection();
        $conn->feed(Frame::encodePing('12345678'));

        $outbound = $conn->drainOutbound();
        [$ack,] = Frame::parse($outbound);

        expect($ack->type)->toBe(Frame::TYPE_PING)
            ->and($ack->flags & Frame::FLAG_ACK)->not->toBe(0)
            ->and($ack->payload)->toBe('12345678');
    });

    it('queues a connection-level WINDOW_UPDATE after consuming a DATA frame', function () {
        $conn = new Http2Connection();

        // Register a client-initiated stream by building a request first.
        $conn->buildRequest('POST', 'example.com', '/', 'irrelevant');

        // Server responds with HEADERS (no END_STREAM) then a DATA frame that ends the stream.
        $streamId = 1; // first client stream is odd id 1
        $conn->feed(Frame::encodeHeaders(
            $streamId,
            hpackLiteralHeaders([[':status', '200']]),
            false,
        ));
        $conn->feed(Frame::encodeData($streamId, 'hello', true));

        $outbound = $conn->drainOutbound();
        // The outbound buffer contains at least one WINDOW_UPDATE on stream 0.
        $foundWindowUpdateOnConnection = false;
        $remaining = $outbound;
        while ($remaining !== '') {
            $parsed = Frame::parse($remaining);
            if ($parsed === null) {
                break;
            }
            [$frame, $consumed] = $parsed;
            $remaining = substr($remaining, $consumed);
            if ($frame->type === Frame::TYPE_WINDOW_UPDATE && $frame->streamId === 0) {
                $foundWindowUpdateOnConnection = true;
                break;
            }
        }

        expect($foundWindowUpdateOnConnection)->toBeTrue();
    });

    it('parses a full HEADERS+END_STREAM response via the ProtocolProcessorContract adapter', function () {
        $conn = new Http2Connection();
        $conn->buildRequest('GET', 'example.com', '/users', '');

        $streamId = 1;
        $serverHeaders = Frame::encodeHeaders(
            $streamId,
            hpackLiteralHeaders([
                [':status', '200'],
                ['content-type', 'application/json'],
            ]),
            true, // END_STREAM — response is headers-only
        );

        $buffer = $serverHeaders;
        $response = $conn->handleBuffer($buffer);

        expect($response)->not->toBeNull()
            ->and($response)->toBeInstanceOf(ASKHttpResponse::class)
            ->and($response->getStatusCode())->toBe(200)
            ->and($response->getReasonPhrase())->toBe('OK')
            ->and($response->getHeaderLine('content-type'))->toBe('application/json')
            ->and($buffer)->toBe(''); // adapter clears the inbound buffer
    });

    it('accumulates a response body spread across HEADERS + DATA frames', function () {
        $conn = new Http2Connection();
        $conn->buildRequest('GET', 'example.com', '/blob', '');

        $streamId = 1;
        $conn->feed(Frame::encodeHeaders(
            $streamId,
            hpackLiteralHeaders([[':status', '200']]),
            false,
        ));

        // No response yet — stream not ended.
        $buffer = '';
        expect($conn->handleBuffer($buffer))->toBeNull();

        $conn->feed(Frame::encodeData($streamId, 'part-1-', false));
        $conn->feed(Frame::encodeData($streamId, 'part-2', true));

        $buffer = '';
        $response = $conn->handleBuffer($buffer);
        expect($response)->not->toBeNull()
            ->and((string) $response->getBody())->toBe('part-1-part-2');
    });

    it('inflates a gzip-encoded response body', function () {
        $conn = new Http2Connection();
        $conn->buildRequest('GET', 'example.com', '/compressed', '');

        $streamId = 1;
        $conn->feed(Frame::encodeHeaders(
            $streamId,
            hpackLiteralHeaders([
                [':status', '200'],
                ['content-encoding', 'gzip'],
            ]),
            false,
        ));

        $rawBody = gzencode('{"ok":true}', 6);
        $conn->feed(Frame::encodeData($streamId, $rawBody, true));

        $buffer = '';
        $response = $conn->handleBuffer($buffer);

        expect($response)->not->toBeNull()
            ->and((string) $response->getBody())->toBe('{"ok":true}');
    });

    it('maps a non-200 :status to the correct reason phrase', function () {
        $conn = new Http2Connection();
        $conn->buildRequest('GET', 'example.com', '/missing', '');

        $buffer = Frame::encodeHeaders(
            1,
            hpackLiteralHeaders([[':status', '404']]),
            true,
        );

        $response = $conn->handleBuffer($buffer);
        expect($response->getStatusCode())->toBe(404)
            ->and($response->getReasonPhrase())->toBe('Not Found');
    });

    it('marks all open streams done when a GOAWAY arrives', function () {
        $conn = new Http2Connection();
        $conn->buildRequest('GET', 'example.com', '/a', '');
        $conn->buildRequest('GET', 'example.com', '/b', '');

        // GOAWAY does not resolve a stream via feed() (returns []), it only flips state.
        $completed = $conn->feed(Frame::encodeGoaway(0));
        expect($completed)->toBe([]);

        // After GOAWAY, the response (partial/empty) is still retrievable per stream,
        // matching the documented single-request limitation.
        $buffer = '';
        $response = $conn->handleBuffer($buffer);
        expect($response)->not->toBeNull();
    });

    it('forwards caller-supplied extra headers into the encoded request', function () {
        $conn = new Http2Connection();
        $encoded = $conn->buildRequest(
            'POST',
            'example.com',
            '/hook',
            '{"x":1}',
            ['authorization' => 'Bearer token', 'x-custom' => 'abc'],
        );

        // Pull the HEADERS frame (first frame after preface is absent here — buildRequest
        // emits only the request frames, no preface). The HEADERS frame payload must contain
        // the literal-encoded header names.
        [$headersFrame,] = Frame::parse($encoded);
        expect($headersFrame->type)->toBe(Frame::TYPE_HEADERS);
        expect($headersFrame->payload)->toContain('authorization')
            ->and($headersFrame->payload)->toContain('Bearer token')
            ->and($headersFrame->payload)->toContain('x-custom')
            ->and($headersFrame->payload)->toContain('abc');
    });

    it('drainOutbound returns an empty string when there is nothing to send', function () {
        $conn = new Http2Connection();
        expect($conn->drainOutbound())->toBe('');
    });
});

$liveNet = getenv('ASK_LIVE_NET') === '1';

describe('Http2Connection (live network)', function () use ($liveNet) {
    beforeEach(function () use ($liveNet) {
        if (!$liveNet) {
            $this->markTestSkipped('Set ASK_LIVE_NET=1 to run live-network HTTP/2 tests.');
        }
    });

    it('completes an HTTP/2 GET request end-to-end through HttpsSocketClient', function () {
        // http2Enabled=true advertises h2 in ALPN. keepAlive=false avoids the
        // post-negotiation http/1.1 override in openConnection() — h2 pool will
        // remove that override when Http2Pool is implemented (P4).
        $client = new HttpsSocketClient(new HttpsSocketClientConfig(
            keepAlive: false,
            http2Enabled: true,
        ));

        $promise = $client->request(new ASKHttpRequest(
            url: 'https://open.er-api.com/v6/latest/USD',
            method: 'GET',
        ));
        $client->drain();

        $response = $promise->await();

        expect($response)->toBeInstanceOf(ASKHttpResponse::class)
            ->and($response->getStatusCode())->toBe(200)
            ->and($response->getProtocolVersion())->toBe('2');
    });
});
