<?php

declare(strict_types=1);

namespace BAGArt\ASKClient\Queue\Adapters;

use BAGArt\ASKClient\Contracts\Queue\ASKQueueAdapterContract;

final class InMemoryQueueAdapter implements ASKQueueAdapterContract
{
    public const string TYPE = 'in_memory';

    /** @var array<string, \SplQueue> */
    private array $fifoQueues = [];

    /** @var array<string, array<string, array<int, array{payload: string, at: int}>>> */
    private array $delayedQueues = [];

    /** @var array<string, array<int, string>> */
    private array $partitions = [];

    public static function build(
        ?string $dsn = null,
    ): self {
        return new self();
    }

    public function push(string $queueName, string $payload): void
    {
        if (!isset($this->fifoQueues[$queueName])) {
            $this->fifoQueues[$queueName] = new \SplQueue();
        }

        $this->fifoQueues[$queueName]->enqueue($payload);
    }

    public function pop(string $queueName): ?string
    {
        if (!isset($this->fifoQueues[$queueName]) || $this->fifoQueues[$queueName]->isEmpty()) {
            return null;
        }

        return $this->fifoQueues[$queueName]->dequeue();
    }

    public function pushDelayed(string $queueName, string $payload, int $availableAt, ?string $partitionKey = null): void
    {
        $partition = $partitionKey ?? 'default';
        $this->delayedQueues[$queueName][$partition][] = [
            'payload' => $payload,
            'at' => $availableAt,
        ];

        if ($partitionKey !== null && !in_array($partitionKey, $this->partitions[$queueName] ?? [], true)) {
            $this->partitions[$queueName][] = $partitionKey;
        }
    }

    public function popDue(string $queueName, ?string $partitionKey = null): ?string
    {
        $partition = $partitionKey ?? 'default';

        if (empty($this->delayedQueues[$queueName][$partition])) {
            return null;
        }

        usort($this->delayedQueues[$queueName][$partition], static fn ($a, $b) => $a['at'] <=> $b['at']);

        if ($this->delayedQueues[$queueName][$partition][0]['at'] <= time()) {
            $item = array_shift($this->delayedQueues[$queueName][$partition]);

            if ($partitionKey !== null && empty($this->delayedQueues[$queueName][$partition])) {
                $this->partitions[$queueName] = array_values(
                    array_filter($this->partitions[$queueName] ?? [], static fn ($k) => $k !== $partitionKey)
                );
            }

            return $item['payload'];
        }

        return null;
    }

    /** @var array<string, int> */
    private array $locks = [];

    public function getPartitions(string $queueName, int $limit = 10, bool $random = true): array
    {
        $partitions = $this->partitions[$queueName] ?? [];

        if (empty($partitions)) {
            return [];
        }

        if ($random) {
            $keys = array_rand(array_flip($partitions), min(count($partitions), $limit));
            return (array) $keys;
        }

        return array_slice($partitions, 0, $limit);
    }

    public function acquirePartition(string $partitionKey, int $ttl): bool
    {
        if (isset($this->locks[$partitionKey]) && $this->locks[$partitionKey] > time()) {
            return false;
        }

        $this->locks[$partitionKey] = time() + $ttl;
        return true;
    }

    public function releasePartition(string $partitionKey): void
    {
        unset($this->locks[$partitionKey]);
    }

    public function hasPartition(string $queueName, string $partitionKey): bool
    {
        return in_array($partitionKey, $this->partitions[$queueName] ?? [], true);
    }

    public function size(string $queueName): int
    {
        $total = isset($this->fifoQueues[$queueName]) ? count($this->fifoQueues[$queueName]) : 0;

        if (!empty($this->delayedQueues[$queueName])) {
            foreach ($this->delayedQueues[$queueName] as $partition) {
                $total += count($partition);
            }
        }

        return $total;
    }
}
