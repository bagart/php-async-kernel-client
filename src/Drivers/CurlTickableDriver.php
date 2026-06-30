<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Drivers;

use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Promise\ASKDeferred;
use CurlHandle;
use CurlMultiHandle;
use GuzzleHttp\Psr7\Message;
use Throwable;

final class CurlTickableDriver implements ASKTickableContract
{
    /** @var array<int, CurlHandle> */
    private array $handles = [];

    /** @var array<int, ASKDeferred> */
    private array $deferreds = [];

    private CurlMultiHandle $multiHandle;

    public function __construct(
        ?CurlMultiHandle $multiHandle = null,
    ) {
        $this->multiHandle = $multiHandle ?? curl_multi_init();
    }

    public function addHandle(CurlHandle $ch): void
    {
        $result = curl_multi_add_handle($this->multiHandle, $ch);

        if ($result !== CURLM_OK) {
            @curl_close($ch);

            throw new ASKNetworkException(
                'Failed to add curl handle: '.curl_multi_strerror($result),
            );
        }

        $id = spl_object_id($ch);

        $this->handles[$id] = $ch;
    }

    public function registerDeferred(int $id, ASKDeferred $deferred): void
    {
        $this->deferreds[$id] = $deferred;
    }

    public function removeHandle(CurlHandle $ch): void
    {
        $id = $this->findHandleId($ch);

        if ($id === null) {
            return;
        }

        curl_multi_remove_handle($this->multiHandle, $this->handles[$id]);

        unset(
            $this->handles[$id],
            $this->deferreds[$id],
        );

        @curl_close($ch);
    }

    public function tick(int $systemPressure): void
    {
        do {
            $status = curl_multi_exec($this->multiHandle, $active);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        while (($info = curl_multi_info_read($this->multiHandle)) !== false) {
            if (($info['msg'] ?? null) !== CURLMSG_DONE) {
                continue;
            }

            /** @var CurlHandle $completedHandle */
            $completedHandle = $info['handle'];

            $id = $this->findHandleId($completedHandle);

            if ($id === null) {
                continue;
            }

            $originalHandle = $this->handles[$id];

            $errno = curl_errno($originalHandle);
            $error = curl_error($originalHandle);

            if (isset($this->deferreds[$id])) {
                try {
                    if ($errno !== 0) {
                        $this->deferreds[$id]->reject(
                            new ASKNetworkException(
                                "curl error [{$errno}]: {$error}"
                            )
                        );
                    } else {
                        $raw = curl_multi_getcontent($originalHandle);

                        if ($raw === false) {
                            throw new ASKNetworkException(
                                'Failed to read response body'
                            );
                        }

                        $response = Message::parseResponse($raw);

                        $this->deferreds[$id]->resolve($response);
                    }
                } catch (Throwable $e) {
                    $this->deferreds[$id]->reject(
                        $e instanceof ASKNetworkException
                            ? $e
                            : new ASKNetworkException(
                                $e->getMessage(),
                                previous: $e,
                            )
                    );
                }

                unset($this->deferreds[$id]);
            }

            curl_multi_remove_handle($this->multiHandle, $originalHandle);

            unset($this->handles[$id]);

            @curl_close($originalHandle);
        }

        if ($active > 0) {
            curl_multi_select($this->multiHandle, 0);
        }
    }

    public function pressure(): int
    {
        return 0;
    }

    public function execute(bool $repeatUntilPerform = false, int &$active = 0): int
    {
        do {
            $status = curl_multi_exec($this->multiHandle, $active);
        } while (
            $repeatUntilPerform &&
            $status === CURLM_CALL_MULTI_PERFORM
        );

        return $status;
    }

    public function select(float $timeoutSec = 0.1): int
    {
        return curl_multi_select($this->multiHandle, $timeoutSec);
    }

    public function queueSize(): int
    {
        return count($this->handles);
    }

    public function isIdle(): bool
    {
        return $this->handles === [];
    }

    public function close(): void
    {
        foreach ($this->handles as $id => $ch) {
            if (isset($this->deferreds[$id])) {
                $this->deferreds[$id]->reject(
                    new ASKNetworkException(
                        'Connection closed before completion'
                    )
                );
            }

            try {
                curl_multi_remove_handle($this->multiHandle, $ch);
            } catch (Throwable) {
            }

            try {
                curl_close($ch);
            } catch (Throwable) {
            }
        }

        $this->handles = [];
        $this->deferreds = [];

        try {
            curl_multi_close($this->multiHandle);
        } catch (Throwable) {
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function findHandleId(CurlHandle $needle): ?int
    {
        foreach ($this->handles as $id => $handle) {
            if ($handle === $needle) {
                return $id;
            }
        }

        return null;
    }
}
