<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Queue\Adapters;

use BAGArt\ASKClient\Contracts\Queue\ASKQueueAdapterContract;
use BAGArt\AsyncKernel\Wrappers\ASKCacheWrapper;

final class CacheQueueAdapter implements ASKQueueAdapterContract
{
    private const string PARTITION_INDEX_SUFFIX = ':partitions';

    public function __construct(
        private readonly ASKCacheWrapper $cache,
    ) {
    }

    public function push(string $queueName, string $payload): void
    {
        $tailKey = $queueName . ':tail';

        if (method_exists($this->cache, 'increment')) {
            $index = $this->cache->increment($tailKey, 1);
            if ($index === false) {
                $this->cache->set($tailKey, 1);
                $index = 1;
            }
        } else {
            $index = ((int) $this->cache->get($tailKey, 0)) + 1;
            $this->cache->set($tailKey, $index);
        }

        $this->cache->set($queueName . ':' . $index, $payload);
    }

    public function pop(string $queueName): ?string
    {
        $headKey = $queueName . ':head';
        $tailKey = $queueName . ':tail';

        $head = (int) $this->cache->get($headKey, 1);
        $tail = (int) $this->cache->get($tailKey, 0);

        if ($head > $tail) {
            return null;
        }

        $itemKey = $queueName . ':' . $head;
        $payload = $this->cache->get($itemKey);

        if ($payload !== null && $payload !== false) {
            $this->cache->delete($itemKey);

            if (method_exists($this->cache, 'increment')) {
                $this->cache->increment($headKey, 1);
            } else {
                $this->cache->set($headKey, $head + 1);
            }
            return (string) $payload;
        }

        return null;
    }

    public function pushDelayed(string $queueName, string $payload, int $availableAt, ?string $partitionKey = null): void
    {
        $partition = $partitionKey ?? 'default';
        $targetKey = $queueName . ':delayed:' . $partition;

        /** @var array<int, array{payload: string, at: int}> $list */
        $list = $this->cache->get($targetKey, []);
        $list[] = ['payload' => $payload, 'at' => $availableAt];

        $this->cache->set($targetKey, $list);

        if ($partitionKey !== null) {
            $indexKey = $queueName . self::PARTITION_INDEX_SUFFIX;
            /** @var array<int, string> $partitions */
            $partitions = $this->cache->get($indexKey, []);
            if (!in_array($partitionKey, $partitions, true)) {
                $partitions[] = $partitionKey;
                $this->cache->set($indexKey, $partitions);
            }
        }
    }

    public function popDue(string $queueName, ?string $partitionKey = null): ?string
    {
        $partition = $partitionKey ?? 'default';
        $targetKey = $queueName . ':delayed:' . $partition;

        /** @var array<int, array{payload: string, at: int}> $list */
        $list = $this->cache->get($targetKey, []);

        if ($list === []) {
            return null;
        }

        usort($list, static fn ($a, $b) => $a['at'] <=> $b['at']);

        $now = time();
        if ($list[0]['at'] <= $now) {
            $item = array_shift($list);
            $this->cache->set($targetKey, $list);
            return $item['payload'];
        }

        return null;
    }
    public function getPartitions(string $queueName, int $limit = 10, bool $random = true): array
    {
        $partitions = (array) $this->cache->get($queueName . self::PARTITION_INDEX_SUFFIX, []);

        if ($random && count($partitions) > $limit) {
            $keys = array_rand(array_flip($partitions), $limit);
            return (array) $keys;
        }

        return array_slice($partitions, 0, $limit);
    }

    public function acquirePartition(string $partitionKey, int $ttl): bool
    {
        $lockKey = 'lock:' . $partitionKey;

        return $this->cache->add($lockKey, 'locked', $ttl);
    }

    public function releasePartition(string $partitionKey): void
    {
        $this->cache->delete('lock:' . $partitionKey);
    }

    public function hasPartition(string $queueName, string $partitionKey): bool
    {
        $partitions = $this->cache->get($queueName . self::PARTITION_INDEX_SUFFIX, []);
        return in_array($partitionKey, (array) $partitions, true);
    }

    public function size(string $queueName): int
    {
        $head = (int) $this->cache->get($queueName . ':head', 1);
        $tail = (int) $this->cache->get($queueName . ':tail', 0);
        $fifoSize = $tail - $head + 1;
        $total = $fifoSize > 0 ? $fifoSize : 0;

        $partitions = $this->getPartitions($queueName);
        $partitions[] = 'default';

        foreach ($partitions as $partition) {
            $list = $this->cache->get($queueName . ':delayed:' . $partition, []);
            $total += count((array) $list);
        }

        return $total;
    }
}
