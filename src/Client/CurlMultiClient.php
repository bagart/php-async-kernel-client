<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Client;

use BAGArt\ASKClient\Contracts\Client\NetworkClientContract;
use BAGArt\ASKClient\Drivers\CurlTickableDriver;
use BAGArt\ASKClient\Exceptions\ASKNetworkException;
use BAGArt\ASKClient\Request\ASKHttpRequest;
use BAGArt\AsyncKernel\Contracts\ASKPromiseContract;
use BAGArt\AsyncKernel\Promise\ASKDeferred;
use CurlHandle;
use CurlMultiHandle;

final class CurlMultiClient implements NetworkClientContract
{
    private readonly CurlTickableDriver $driver;

    public function __construct(
        ?CurlMultiHandle $multiHandle = null,
    ) {
        $this->driver = new CurlTickableDriver($multiHandle);
    }

    public function request(ASKHttpRequest $request): ASKPromiseContract
    {
        $ch = curl_init();

        if (!$ch instanceof CurlHandle) {
            throw new ASKNetworkException('Failed to initialize curl handle.');
        }

        $method = strtoupper($request->method);

        if ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

            if ($request->body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $request->body);
            }
        }

        if ($request->body !== null && !isset($request->headers['Content-Type'])) {
            $request->headers['Content-Type'] = 'application/json';
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $request->getUrlWithQuery(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '',
            CURLOPT_FAILONERROR => false,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $request->formattedHeaders(),
        ]);

        foreach ($request->curlOptions as $option => $value) {
            curl_setopt($ch, $option, $value);
        }

        $this->driver->addHandle($ch);

        $id = spl_object_id($ch);

        $deferred = new ASKDeferred();
        $this->driver->registerDeferred($id, $deferred);

        return $deferred->promise();
    }

    public function tickable(): array
    {
        return [$this->driver];
    }

    public function tick(int $systemPressure = 0): void
    {
        $this->driver->tick($systemPressure);
    }

    public function isIdle(): bool
    {
        return $this->driver->isIdle();
    }

    public function queueSize(): int
    {
        return $this->driver->queueSize();
    }

    public function execute(bool $repeatUntilPerform = false, int &$active = 0): int
    {
        return $this->driver->execute($repeatUntilPerform, $active);
    }

    /**
     * @return \Generator<int, CurlHandle>
     */
    public function readCompletedHandles(): \Generator
    {
        return $this->driver->readCompletedHandles();
    }

    public function readCompletedHandle(): ?CurlHandle
    {
        return $this->driver->readCompletedHandle();
    }

    public function remove(CurlHandle $ch): void
    {
        $this->driver->removeHandle($ch);
    }

    public function select(float $timeoutSec = 0.1): int
    {
        return $this->driver->select($timeoutSec);
    }

    public function close(): void
    {
        $this->driver->close();
    }

    public function __destruct()
    {
        $this->close();
    }
}
