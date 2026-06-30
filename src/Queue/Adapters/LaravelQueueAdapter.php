<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Queue\Adapters;

use BAGArt\ASKClient\Contracts\Queue\ASKQueueAdapterContract;
use Illuminate\Contracts\Queue\Queue as LaravelQueueContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Facades\Redis as LaravelRedis;

final class LaravelQueueAdapter implements ASKQueueAdapterContract
{
    private const string PARTITION_INDEX_SUFFIX = ':partitions';

    public function __construct(
        private readonly LaravelQueueContract $laravelQueue,
    ) {
    }

    public function push(string $queueName, string $payload): void
    {
        $this->laravelQueue->push(new QueueLaravelJob($payload), '', $queueName);
    }

    public function pop(string $queueName): ?string
    {
        /** @var Job|null $job */
        $job = $this->laravelQueue->pop($queueName);

        if ($job === null) {
            return null;
        }

        $rawData = json_decode($job->getRawBody(), true, 512, JSON_THROW_ON_ERROR);

        $payload = unserialize(
            $rawData['data']['command'],
            ['allowed_classes' => true],
        )->payload;

        $job->delete();

        return $payload;
    }

    public function pushDelayed(
        string $queueName,
        string $payload,
        int $availableAt,
        ?string $partitionKey = null
    ): void {
        $delay = $availableAt - time();
        $delay = $delay > 0 ? $delay : 0;

        $targetQueue = $queueName.($partitionKey !== null ? ':'.$partitionKey : '');
        $this->laravelQueue->later($delay, new QueueLaravelJob($payload), '', $targetQueue);

        if ($partitionKey !== null) {
            LaravelRedis::sadd($queueName.self::PARTITION_INDEX_SUFFIX, $partitionKey);
        }
    }

    public function popDue(string $queueName, ?string $partitionKey = null): ?string
    {
        $targetQueue = $queueName.':'.($partitionKey ?? 'default');

        $script = '
            local val = redis.call("zrangebyscore", KEYS[1], 0, ARGV[1], "LIMIT", 0, 1)
            if val[1] then
                redis.call("zrem", KEYS[1], val[1])
                return val[1]
            end
            return nil
        ';

        $result = LaravelRedis::eval($script, 1, $targetQueue, time());

        if ($result === false || $result === null) {
            if ($partitionKey !== null && LaravelRedis::zcard($targetQueue) === 0) {
                LaravelRedis::srem($queueName.self::PARTITION_INDEX_SUFFIX, $partitionKey);
            }
            return null;
        }

        $rawData = json_decode((string)$result, true, 512, JSON_THROW_ON_ERROR);

        return unserialize(
            $rawData['data']['command'],
            ['allowed_classes' => true],
        )->payload;
    }

    public function getPartitions(string $queueName, int $limit = 10, bool $random = true): array
    {
        $key = $queueName . self::PARTITION_INDEX_SUFFIX;

        if ($random) {
            return LaravelRedis::srandmember($key, $limit) ?: [];
        }

        return LaravelRedis::smembers($key) ?: [];
    }

    public function acquirePartition(string $partitionKey, int $ttl): bool
    {
        return (bool) LaravelRedis::set('lock:partition:' . $partitionKey, '1', 'EX', $ttl, 'NX');
    }

    public function releasePartition(string $partitionKey): void
    {
        LaravelRedis::del('lock:partition:' . $partitionKey);
    }

    public function hasPartition(string $queueName, string $partitionKey): bool
    {
        return (bool) LaravelRedis::sismember($queueName . self::PARTITION_INDEX_SUFFIX, $partitionKey);
    }

    public function size(string $queueName): int
    {
        return $this->laravelQueue->size($queueName);
    }
}
