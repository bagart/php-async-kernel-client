<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client\HttpsSocketClient;

final class AsyncDnsResolver
{
    private const int DNS_PORT = 53;
    private const int DNS_OVER_TLS_PORT = 853;
    private const float QUERY_TIMEOUT = 3.0;
    private const int MAX_RETRIES = 1;
    private const int TCP_READ_SIZE = 4096;

    private const array WELL_KNOWN_DNS = [
        '8.8.8.8',    // Google primary
        '1.1.1.1',    // Cloudflare
        '8.8.4.4',    // Google secondary
        '1.0.0.1',    // Cloudflare secondary
    ];

    /** @var array<int, string> */
    private readonly array $dnsServers;

    /** @var array<string, array{ip: string, expiresAt: float}> */
    private static array $globalCache = [];

    /** @var array<string, array{expiresAt: float}> */
    private static array $failureCache = [];

    /** @var array<string, array{sockets: array<int, mixed>, host: string, queryId: int, sentAt: float, retries: int}> */
    private array $pending = [];

    /** @var array<string, string> Just-resolved entries to return immediately within the same tick */
    private array $fresh = [];

    private readonly float $ttl;

    private readonly float $failureTtl;

    private readonly bool $useTls;

    /** @var array<int, string> Resource ID => TCP read buffer (for TLS DNS framing) */
    private array $tcpBuffers = [];

    public function __construct(
        float $ttl = 60.0,
        float $failureTtl = 10.0,
        ?array $dnsServers = null,
        bool $useTls = false,
    ) {
        $this->ttl = $ttl;
        $this->failureTtl = $failureTtl;
        $this->dnsServers = $dnsServers ?? self::loadSystemDnsServers();
        $this->useTls = $useTls;
    }

    /** @return array<int, string> */
    private static function loadSystemDnsServers(): array
    {
        $servers = [];

        $resolv = @file_get_contents('/etc/resolv.conf');
        if ($resolv !== false) {
            foreach (explode("\n", $resolv) as $line) {
                if (preg_match('/^nameserver\s+(\S+)/i', $line, $m)) {
                    $ip = trim($m[1]);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        $servers[] = $ip;
                    }
                }
            }
        }

        return $servers !== [] ? $servers : self::WELL_KNOWN_DNS;
    }

    /** @return array<int, mixed> */
    public function getReadSockets(): array
    {
        $sockets = [];
        foreach ($this->pending as $info) {
            foreach ($info['sockets'] as $sock) {
                if (is_resource($sock)) {
                    $sockets[] = $sock;
                }
            }
        }
        return $sockets;
    }

    /**
     * Attempt to resolve a hostname. Returns the IP string when instantly available
     * (static cache hit or just-resolved in this tick). Returns null when a UDP query
     * has been dispatched and the caller should retry on the next tick.
     */
    public function resolve(string $host): ?string
    {
        $entry = self::$globalCache[$host] ?? null;

        if ($entry !== null && $entry['expiresAt'] > microtime(true)) {
            return $entry['ip'];
        }

        $failed = self::$failureCache[$host] ?? null;

        if ($failed !== null && $failed['expiresAt'] > microtime(true)) {
            return null;
        }

        if (isset($this->fresh[$host])) {
            return $this->fresh[$host];
        }

        if (isset($this->pending[$host])) {
            return null;
        }

        $this->dispatchQuery($host);

        return null;
    }

    /**
     * Called at the start of every event-loop tick. Handles timeouts and retries.
     */
    public function tick(): void
    {
        $now = microtime(true);

        foreach ($this->pending as $host => $info) {
            if ($now - $info['sentAt'] > self::QUERY_TIMEOUT) {
                if ($info['retries'] < self::MAX_RETRIES) {
                    $this->retry($host);
                } else {
                    $this->fallback($host);
                }
            }
        }
    }

    /**
     * Process a readable DNS response socket (returned by getReadSockets).
     * Returns true when the socket belonged to this resolver.
     */
    public function processReadable(mixed $socket): bool
    {
        foreach ($this->pending as $host => $info) {
            $matched = false;
            $idx = null;
            foreach ($info['sockets'] as $k => $sock) {
                if ($sock === $socket) {
                    $matched = true;
                    $idx = $k;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }

            if ($this->useTls) {
                return $this->processTcpResponse($host, $info, $socket, $idx);
            }

            $data = @fread($socket, 512);

            if ($data === false || $data === '') {
                // This server returned empty — don't penalise, other
                // servers might still respond. Remove this socket only.
                /** @var array<int, mixed> $sockets */
                $sockets = &$this->pending[$host]['sockets'];
                $this->closeSocket($socket);
                foreach ($sockets as $k => $s) {
                    if ($s === $socket) {
                        unset($sockets[$k]);
                        break;
                    }
                }
                if ($sockets === []) {
                    $this->retryOrFallback($host);
                }

                return true;
            }

            $ip = self::parseARecord($info['queryId'], $data);

            if ($ip !== null) {
                self::$globalCache[$host] = [
                    'ip' => $ip,
                    'expiresAt' => microtime(true) + $this->ttl,
                ];
                $this->fresh[$host] = $ip;
            }

            $this->closePending($host);

            return true;
        }

        return false;
    }

    /**
     * Process a TCP/TLS DNS response with length-prefixed framing (RFC 1035 §4.2.2).
     */
    private function processTcpResponse(string $host, array $info, mixed $socket, ?int $idx): bool
    {
        $data = @fread($socket, self::TCP_READ_SIZE);

        if ($data === false || $data === '') {
            // Connection closed or error — remove this socket
            $this->closeSocket($socket);
            if ($idx !== null) {
                unset($this->pending[$host]['sockets'][$idx]);
            }
            if (empty($this->pending[$host]['sockets'])) {
                $this->retryOrFallback($host);
            }
            return true;
        }

        $socketId = (int)$socket;
        $buf = ($this->tcpBuffers[$socketId] ?? '') . $data;
        $this->tcpBuffers[$socketId] = $buf;

        // Process complete messages from buffer
        while (strlen($this->tcpBuffers[$socketId]) >= 2) {
            $msgLen = unpack('n', $this->tcpBuffers[$socketId])[1];
            if (strlen($this->tcpBuffers[$socketId]) < 2 + $msgLen) {
                break; // incomplete message, wait for more data
            }

            $dnsMsg = substr($this->tcpBuffers[$socketId], 2, $msgLen);
            $this->tcpBuffers[$socketId] = substr($this->tcpBuffers[$socketId], 2 + $msgLen);

            $ip = self::parseARecord($info['queryId'], $dnsMsg);

            if ($ip !== null) {
                self::$globalCache[$host] = [
                    'ip' => $ip,
                    'expiresAt' => microtime(true) + $this->ttl,
                ];
                $this->fresh[$host] = $ip;
                $this->closePending($host);
                return true;
            }
        }

        return true;
    }

    public function flushFresh(): array
    {
        $result = $this->fresh;
        $this->fresh = [];

        return $result;
    }

    public static function clearCache(): void
    {
        self::$globalCache = [];
        self::$failureCache = [];
    }

    // ---------------------------------------------------------------
    //  Internal
    // ---------------------------------------------------------------

    private function dispatchQuery(string $host): void
    {
        $queryId = random_int(0, 0xFFFF);
        $packet = self::buildQuery($queryId, $host);
        $sockets = [];

        $port = $this->useTls ? self::DNS_OVER_TLS_PORT : self::DNS_PORT;

        foreach ($this->dnsServers as $server) {
            $uri = $this->useTls ? 'tls://'.$server.':'.$port : 'udp://'.$server.':'.$port;

            $socket = @stream_socket_client(
                $uri,
                $errno,
                $errstr,
                3.0,
            );

            if (!$socket) {
                continue;
            }

            stream_set_blocking($socket, false);

            // TCP/TLS DNS uses a 2-byte length prefix (RFC 1035 §4.2.2)
            $writePacket = $this->useTls ? pack('n', strlen($packet)).$packet : $packet;

            if (@fwrite($socket, $writePacket) === false) {
                $this->closeSocket($socket);
                continue;
            }

            $sockets[] = $socket;
        }

        if ($sockets === []) {
            $this->fallback($host);

            return;
        }

        $this->pending[$host] = [
            'sockets' => $sockets,
            'host' => $host,
            'queryId' => $queryId,
            'sentAt' => microtime(true),
            'retries' => 0,
        ];
    }

    private function retry(string $host): void
    {
        $info = $this->pending[$host] ?? null;

        if ($info === null) {
            return;
        }

        $this->closeSockets($info['sockets']);
        $info['retries']++;
        $info['sentAt'] = microtime(true);

        $packet = self::buildQuery($info['queryId'], $host);
        $sockets = [];

        $port = $this->useTls ? self::DNS_OVER_TLS_PORT : self::DNS_PORT;

        foreach ($this->dnsServers as $server) {
            $uri = $this->useTls ? 'tls://'.$server.':'.$port : 'udp://'.$server.':'.$port;

            $socket = @stream_socket_client(
                $uri,
                $errno,
                $errstr,
                3.0,
            );

            if (!$socket) {
                continue;
            }

            stream_set_blocking($socket, false);

            $writePacket = $this->useTls ? pack('n', strlen($packet)).$packet : $packet;

            if (@fwrite($socket, $writePacket) === false) {
                $this->closeSocket($socket);
                continue;
            }

            $sockets[] = $socket;
        }

        if ($sockets === []) {
            $this->fallback($host);

            return;
        }

        $info['sockets'] = $sockets;
        $this->pending[$host] = $info;
    }

    private function retryOrFallback(string $host): void
    {
        $info = $this->pending[$host] ?? null;

        if ($info === null) {
            return;
        }

        if ($info['retries'] < self::MAX_RETRIES) {
            $this->retry($host);
        } else {
            $this->fallback($host);
        }
    }

    private const float FALLBACK_TIMEOUT = 2.0;

    private function fallback(string $host): void
    {
        $this->closePending($host);

        // gethostbyname is synchronous and blocks the entire event loop
        // indefinitely. In an async runtime this stalls all concurrent
        // I/O — run it in a subprocess with a 2-second timeout.
        $ip = self::fallbackResolve($host);

        if ($ip !== null && $ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
            self::$globalCache[$host] = [
                'ip' => $ip,
                'expiresAt' => microtime(true) + $this->ttl,
            ];
            $this->fresh[$host] = $ip;

            return;
        }

        self::$failureCache[$host] = [
            'expiresAt' => microtime(true) + $this->failureTtl,
        ];
    }

    private static function fallbackResolve(string $host): ?string
    {
        $process = @proc_open(
            [PHP_BINARY, '-r', 'echo @gethostbyname(' . var_export($host, true) . ');'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $reads = [$pipes[1]];
        $write = null;
        $except = null;
        $result = @stream_select($reads, $write, $except, (int)self::FALLBACK_TIMEOUT, 0);

        if ($result === false || $result === 0) {
            fclose($pipes[1]);
            fclose($pipes[2]);
            @proc_terminate($process, 9);
            @proc_close($process);

            return null;
        }

        $ip = trim((string)stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = @proc_get_status($process);
        if ($status && $status['running']) {
            @proc_terminate($process, 9);
        }
        @proc_close($process);

        return $ip !== '' ? $ip : null;
    }

    private function closePending(string $host): void
    {
        $info = $this->pending[$host] ?? null;

        if ($info !== null) {
            $this->closeSockets($info['sockets']);
        }

        unset($this->pending[$host]);
    }

    /** @param array<int, mixed> $sockets */
    private function closeSockets(array $sockets): void
    {
        foreach ($sockets as $socket) {
            $this->closeSocket($socket);
        }
    }

    private function closeSocket(mixed $socket): void
    {
        if (is_resource($socket)) {
            unset($this->tcpBuffers[(int)$socket]);
            @fclose($socket);
        }
    }

    // ---------------------------------------------------------------
    //  DNS wire-format helpers
    // ---------------------------------------------------------------

    /**
     * Build a DNS query packet for an A record.
     */
    private static function buildQuery(int $queryId, string $host): string
    {
        $header = pack(
            'nnnnnn',
            $queryId,
            0x0100,         // flags: RD=1 (recursion desired)
            1,              // QDCOUNT: 1 question
            0,              // ANCOUNT
            0,              // NSCOUNT
            0,              // ARCOUNT
        );

        $labels = '';
        foreach (explode('.', $host) as $part) {
            $labels .= chr(strlen($part)).$part;
        }
        $labels .= "\x00";

        $question = $labels
            .pack('nn', 1, 1); // QTYPE=A, QCLASS=IN

        return $header.$question;
    }

    /**
     * Parse an A-record answer from a raw DNS response.
     */
    private static function parseARecord(int $expectedId, string $data): ?string
    {
        $len = strlen($data);

        if ($len < 12) {
            return null;
        }

        $hdr = unpack('nid/nflags/nqdcount/nancount', $data);

        if (!$hdr || $hdr['id'] !== $expectedId) {
            return null;
        }

        if (($hdr['flags'] & 0x000F) !== 0) {
            return null;
        }

        $offset = 12;

        // Skip questions
        for ($i = 0; $i < $hdr['qdcount']; $i++) {
            $offset = self::skipName($data, $offset);

            if ($offset === false || $offset + 4 > $len) {
                return null;
            }

            $offset += 4;
        }

        // Scan answers
        for ($i = 0; $i < $hdr['ancount']; $i++) {
            $offset = self::skipName($data, $offset);

            if ($offset === false || $offset + 10 > $len) {
                return null;
            }

            $rr = unpack('ntype/nclass/Nttl/nrdlength', substr($data, $offset, 10));

            if (!$rr) {
                return null;
            }

            $offset += 10;

            if ($offset + $rr['rdlength'] > $len) {
                return null;
            }

            if ($rr['type'] === 1 && $rr['rdlength'] === 4) {
                $ip = @inet_ntop(substr($data, $offset, 4));

                if ($ip !== false) {
                    return $ip;
                }
            }

            $offset += $rr['rdlength'];
        }

        return null;
    }

    private static function skipName(string $data, int $offset): int|false
    {
        $len = strlen($data);

        while ($offset < $len) {
            $byte = ord($data[$offset]);

            if ($byte === 0) {
                return $offset + 1;
            }

            if (($byte & 0xC0) === 0xC0) {
                return $offset + 2;
            }

            $offset += 1 + $byte;
        }

        return false;
    }
}
