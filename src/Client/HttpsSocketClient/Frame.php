<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client\HttpsSocketClient;

/**
 * Robust HTTP/2 frame parser and encoder.
 * * Handles strict stream ID masks, framing limits, and automatic generation
 * of CONTINUATION frames for massive header blocks.
 *
 * @internal
 */
final class Frame
{
    public const int TYPE_DATA = 0x00;
    public const int TYPE_HEADERS = 0x01;
    public const int TYPE_PRIORITY = 0x02;
    public const int TYPE_RST_STREAM = 0x03;
    public const int TYPE_SETTINGS = 0x04;
    public const int TYPE_PUSH_PROMISE = 0x05;
    public const int TYPE_PING = 0x06;
    public const int TYPE_GOAWAY = 0x07;
    public const int TYPE_WINDOW_UPDATE = 0x08;
    public const int TYPE_CONTINUATION = 0x09;

    public const int FLAG_END_STREAM = 0x01;
    public const int FLAG_ACK = 0x01;
    public const int FLAG_END_HEADERS = 0x04;
    public const int FLAG_PADDED = 0x08;
    public const int FLAG_PRIORITY = 0x20;

    public const int SETTINGS_HEADER_TABLE_SIZE = 0x01;
    public const int SETTINGS_ENABLE_PUSH = 0x02;
    public const int SETTINGS_MAX_CONCURRENT_STREAMS = 0x03;
    public const int SETTINGS_INITIAL_WINDOW_SIZE = 0x04;
    public const int SETTINGS_MAX_FRAME_SIZE = 0x05;
    public const int SETTINGS_MAX_HEADER_LIST_SIZE = 0x06;

    public const int DEFAULT_MAX_FRAME_SIZE = 16384;
    public const int INITIAL_WINDOW_SIZE = 65535;

    public const string CONNECTION_PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";

    public function __construct(
        public readonly int $length,
        public readonly int $type,
        public readonly int $flags,
        public readonly int $streamId,
        public readonly string $payload,
    ) {
    }

    /**
     * Parse a frame from a raw binary string.
     *
     * @return array{0: self, 1: int}|null
     */
    public static function parse(string $data): ?array
    {
        $len = \strlen($data);

        if ($len < 9) {
            return null;
        }

        // Extract 24-bit length field natively via unpack
        $unpack = \unpack('C3l/Ct/Cf/Nid', $data);
        if ($unpack === false) {
            return null;
        }

        $frameLength = ($unpack['l1'] << 16) | ($unpack['l2'] << 8) | $unpack['l3'];
        $type = $unpack['t'];
        $flags = $unpack['f'];
        // HTTP/2 Stream ID is a 31-bit unsigned integer. Ignore the high reserved bit.
        $streamId = $unpack['id'] & 0x7FFFFFFF;

        $totalLength = 9 + $frameLength;

        if ($len < $totalLength) {
            return null;
        }

        $payload = \substr($data, 9, $frameLength);

        return [
            new self($frameLength, $type, $flags, $streamId, $payload),
            $totalLength,
        ];
    }

    /**
     * @param  array<int, int>  $settings
     */
    public static function encodeSettings(array $settings): string
    {
        $payload = '';

        foreach ($settings as $id => $value) {
            $payload .= \pack('nN', $id, $value);
        }

        return self::encodeFrame(self::TYPE_SETTINGS, 0, 0, $payload);
    }

    public static function encodeSettingsAck(): string
    {
        return self::encodeFrame(self::TYPE_SETTINGS, self::FLAG_ACK, 0, '');
    }

    /**
     * Encode headers. Auto-splits into HEADERS and CONTINUATION frames if size bounds are broken.
     */
    public static function encodeHeaders(int $streamId, string $encodedHeaders, bool $endStream = false): string
    {
        $chunks = \str_split($encodedHeaders, self::DEFAULT_MAX_FRAME_SIZE);
        if ($chunks === false || $chunks === []) {
            $chunks = [''];
        }

        $result = '';
        $totalChunks = \count($chunks);

        for ($i = 0; $i < $totalChunks; $i++) {
            $isFirst = ($i === 0);
            $isLast = ($i === $totalChunks - 1);

            if ($isFirst) {
                $flags = 0;
                if ($endStream && $isLast) {
                    $flags |= self::FLAG_END_STREAM;
                }
                if ($isLast) {
                    $flags |= self::FLAG_END_HEADERS;
                }
                $result .= self::encodeFrame(self::TYPE_HEADERS, $flags, $streamId, $chunks[$i]);
            } else {
                $flags = 0;
                if ($isLast) {
                    $flags |= self::FLAG_END_HEADERS;
                }
                $result .= self::encodeFrame(self::TYPE_CONTINUATION, $flags, $streamId, $chunks[$i]);
            }
        }

        return $result;
    }

    /**
     * Encode payload data. Auto-fragments raw buffer into safe-sized DATA frames.
     */
    public static function encodeData(int $streamId, string $data, bool $endStream = true): string
    {
        if ($data === '') {
            $flags = $endStream ? self::FLAG_END_STREAM : 0;
            return self::encodeFrame(self::TYPE_DATA, $flags, $streamId, '');
        }

        $chunks = \str_split($data, self::DEFAULT_MAX_FRAME_SIZE);
        if ($chunks === false) {
            return '';
        }

        $result = '';
        $totalChunks = \count($chunks);

        for ($i = 0; $i < $totalChunks; $i++) {
            $isLast = ($i === $totalChunks - 1);
            $flags = ($endStream && $isLast) ? self::FLAG_END_STREAM : 0;
            $result .= self::encodeFrame(self::TYPE_DATA, $flags, $streamId, $chunks[$i]);
        }

        return $result;
    }

    public static function encodeWindowUpdate(int $streamId, int $windowSizeIncrement): string
    {
        $payload = \pack('N', $windowSizeIncrement & 0x7FFFFFFF);

        return self::encodeFrame(self::TYPE_WINDOW_UPDATE, 0, $streamId, $payload);
    }

    public static function encodePing(string $opaqueData = ''): string
    {
        $payload = \substr(\str_pad($opaqueData, 8, "\0"), 0, 8);

        return self::encodeFrame(self::TYPE_PING, 0, 0, $payload);
    }

    public static function encodeGoaway(int $lastStreamId, int $errorCode = 0, string $debugData = ''): string
    {
        $payload = \pack('NN', $lastStreamId & 0x7FFFFFFF, $errorCode).$debugData;

        return self::encodeFrame(self::TYPE_GOAWAY, 0, 0, $payload);
    }

    public static function encodeRstStream(int $streamId, int $errorCode = 0): string
    {
        $payload = \pack('N', $errorCode);

        return self::encodeFrame(self::TYPE_RST_STREAM, 0, $streamId, $payload);
    }

    private static function encodeFrame(int $type, int $flags, int $streamId, string $payload): string
    {
        $length = \strlen($payload);

        // Fast native 9-byte frame header generation (24-bit length, type, flags, 32-bit Stream ID)
        $header = \pack(
            'C3CCN',
            ($length >> 16) & 0xFF,
            ($length >> 8) & 0xFF,
            $length & 0xFF,
            $type,
            $flags,
            $streamId & 0x7FFFFFFF
        );

        return $header.$payload;
    }

    public static function encodeInt(int $value): string
    {
        return \pack('N', $value & 0x7FFFFFFF);
    }

    public static function decodeInt(string $data): int
    {
        if (\strlen($data) < 4) {
            return 0;
        }

        $res = \unpack('N', $data);

        return $res ? ($res[1] & 0x7FFFFFFF) : 0;
    }
}
