<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Dns\Adapters;

use BAGArt\ASKClient\Dns\AskDnsAdapterContract;
use BAGArt\ASKClient\Dns\AskDnsConfig;
use BAGArt\ASKClient\Exceptions\AskConfigException;
use React\Dns\Model\Message;
use React\Dns\Model\Record;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Protocol\Parser;

final class ReactDnsAdapter implements AskDnsAdapterContract
{
    public const string TYPE = 'react';
    public const bool TLS_SUPPORTED = true;

    private const int DNS_PORT = 53;
    private const int DNS_OVER_TLS_PORT = 853;

    private readonly BinaryDumper $binaryDumper;
    private readonly Parser $parser;

    /** @var array<int, string> */
    private readonly array $dnsServers;

    /** @var array<string, array{ip: string, expiresAt: float}> */
    private static array $globalCache = [];

    /** @var array<string, array{expiresAt: float}> */
    private static array $failureCache = [];

    /** @var array<string, array{sockets: array<int, mixed>, host: string, queryId: int, sentAt: float, retries: int, useTls: bool}> */
    private array $pending = [];

    /** @var array<string, string> */
    private array $fresh = [];

    private readonly float $ttl;
    private readonly float $failureTtl;
    private readonly float $timeout;
    private readonly int $maxRetries;
    private readonly bool $useTls;

    public function __construct(
        private readonly AskDnsConfig $config,
    ) {
        if (!class_exists(Parser::class)) {
            throw new AskConfigException('react/dns is not installed. Run: composer require --dev react/dns');
        }

        $this->binaryDumper = new BinaryDumper();
        $this->parser = new Parser();
        $this->dnsServers = $this->config->dnsServers();
        $this->ttl = $this->config->ttl();
        $this->failureTtl = $this->config->failureTtl();
        $this->timeout = $this->config->timeout();
        $this->maxRetries = 1;
        $this->useTls = $this->config->useTls();
    }

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

    public function tick(): void
    {
        $now = microtime(true);

        foreach ($this->pending as $host => $info) {
            if ($now - $info['sentAt'] > $this->timeout) {
                if ($info['retries'] < $this->maxRetries) {
                    $this->retry($host);
                } else {
                    $this->markFailed($host);
                }
            }
        }
    }

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

    public function processReadable(mixed $socket): bool
    {
        foreach ($this->pending as $host => $info) {
            $matched = false;
            foreach ($info['sockets'] as $idx => $sock) {
                if ($sock === $socket) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }

            $data = @fread($socket, 512);

            if ($data === false || $data === '') {
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

            try {
                $message = $this->parser->parseMessage($data);
            } catch (\Throwable) {
                return false;
            }

            if ($message->rcode !== Message::RCODE_OK) {
                $this->closePending($host);
                return true;
            }

            foreach ($message->answers as $answer) {
                $ip = $answer->data;
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    self::$globalCache[$host] = [
                        'ip' => $ip,
                        'expiresAt' => microtime(true) + $this->ttl,
                    ];
                    $this->fresh[$host] = $ip;
                    break;
                }
            }

            $this->closePending($host);
            return true;
        }

        return false;
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

    public function warmUp(string $host): ?string
    {
        $ip = $this->resolve($host);
        if ($ip !== null) {
            return $ip;
        }

        $deadline = microtime(true) + $this->timeout;
        while (microtime(true) < $deadline) {
            $this->tick();
            $sockets = $this->getReadSockets();
            if ($sockets !== []) {
                $read = $sockets;
                $write = null;
                $except = null;
                if (@stream_select($read, $write, $except, 0, 100000) > 0) {
                    foreach ($read as $socket) {
                        $this->processReadable($socket);
                    }
                }
            }
            $fresh = $this->flushFresh();
            if (isset($fresh[$host])) {
                return $fresh[$host];
            }
        }

        return null;
    }

    private function dispatchQuery(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            self::$globalCache[$host] = [
                'ip' => $host,
                'expiresAt' => microtime(true) + $this->ttl,
            ];
            $this->fresh[$host] = $host;
            return;
        }

        $queryId = random_int(0, 0xFFFF);
        $message = new Message();
        $message->id = $queryId;
        $message->rd = true;
        $message->questions[] = new Record($host, Message::TYPE_A, Message::CLASS_IN, 0, null);

        $packet = $this->binaryDumper->toBinary($message);
        $sockets = [];
        $port = $this->useTls ? self::DNS_OVER_TLS_PORT : self::DNS_PORT;

        foreach ($this->dnsServers as $server) {
            $uri = $this->useTls ? 'tls://' : 'udp://';
            $socket = @stream_socket_client(
                $uri . $server . ':' . $port,
                $errno,
                $errstr,
                (int)$this->timeout,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            );

            if (!$socket) {
                continue;
            }

            stream_set_blocking($socket, false);

            if ($this->useTls) {
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                $result = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
                if ($result === false) {
                    $this->closeSocket($socket);
                    continue;
                }
            }

            if (@fwrite($socket, $packet) === false) {
                $this->closeSocket($socket);
                continue;
            }

            $sockets[] = $socket;
        }

        if ($sockets === []) {
            self::$failureCache[$host] = [
                'expiresAt' => microtime(true) + $this->failureTtl,
            ];
            return;
        }

        $this->pending[$host] = [
            'sockets' => $sockets,
            'host' => $host,
            'queryId' => $queryId,
            'sentAt' => microtime(true),
            'retries' => 0,
            'useTls' => $this->useTls,
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

        $packet = $this->binaryDumper->toBinary((function () use ($info) {
            $m = new Message();
            $m->id = $info['queryId'];
            $m->rd = true;
            $m->questions[] = new Record($info['host'], Message::TYPE_A, Message::CLASS_IN, 0, null);
            return $m;
        })());

        $sockets = [];
        $port = $this->useTls ? self::DNS_OVER_TLS_PORT : self::DNS_PORT;

        foreach ($this->dnsServers as $server) {
            $uri = $this->useTls ? 'tls://' : 'udp://';
            $socket = @stream_socket_client(
                $uri . $server . ':' . $port,
                $errno,
                $errstr,
                (int)$this->timeout,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            );
            if (!$socket) {
                continue;
            }
            stream_set_blocking($socket, false);
            if ($this->useTls) {
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                @stream_socket_enable_crypto($socket, true, $cryptoMethod);
            }
            if (@fwrite($socket, $packet) === false) {
                $this->closeSocket($socket);
                continue;
            }
            $sockets[] = $socket;
        }

        if ($sockets === []) {
            $this->markFailed($host);
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
        if ($info['retries'] < $this->maxRetries) {
            $this->retry($host);
        } else {
            $this->markFailed($host);
        }
    }

    private function markFailed(string $host): void
    {
        $this->closePending($host);
        self::$failureCache[$host] = [
            'expiresAt' => microtime(true) + $this->failureTtl,
        ];
    }

    private function closePending(string $host): void
    {
        $info = $this->pending[$host] ?? null;
        if ($info !== null) {
            $this->closeSockets($info['sockets']);
        }
        unset($this->pending[$host]);
    }

    private function closeSockets(array $sockets): void
    {
        foreach ($sockets as $socket) {
            $this->closeSocket($socket);
        }
    }

    private function closeSocket(mixed $socket): void
    {
        if (is_resource($socket)) {
            @fclose($socket);
        }
    }
}
