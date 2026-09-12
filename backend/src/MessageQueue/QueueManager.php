<?php

declare(strict_types=1);

namespace App\MessageQueue;

use App\Cache\CacheNamespace;
use App\Service\RedisFactory;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Exception\TransportException;

final class QueueManager extends AbstractQueueManager
{
    /** @var Connection[] */
    private array $connections = [];

    public function __construct(
        private readonly RedisFactory $redisFactory
    ) {
    }

    public function clearQueue(QueueNames $queue): void
    {
        $connection = $this->getConnection($queue);

        // Keep the Redis stream and Symfony consumer group alive while clearing its
        // entries. Connection::cleanup() deletes the stream/group, which races a
        // long-lived Messenger worker blocked in XREADGROUP and produces NOGROUP.
        // XTRIM removes queued entries without destroying the group metadata.
        $connection->setup();

        $stream = CacheNamespace::Messages->value . ':' . $queue->value;
        $this->redisFactory->getInstance()->rawCommand(
            'XTRIM',
            $stream,
            'MAXLEN',
            '0'
        );
    }

    public function getTransport(QueueNames $queue): RedisTransport
    {
        return new RedisTransport($this->getConnection($queue));
    }

    /**
     * @return RedisTransport[]
     */
    public function getTransports(): array
    {
        $transports = [];
        foreach (QueueNames::cases() as $queue) {
            $transports[$queue->value] = $this->getTransport($queue);
        }
        return $transports;
    }

    private function getConnection(QueueNames $queue): Connection
    {
        $queueName = $queue->value;

        if (!isset($this->connections[$queueName])) {
            $this->connections[$queueName] = new Connection(
                [
                    'lazy' => true,
                    'stream' => CacheNamespace::Messages->value . ':' . $queueName,
                    'delete_after_ack' => true,
                    'redeliver_timeout' => 43200,
                    'claim_interval' => 86400,
                ],
                $this->redisFactory->getInstance()
            );
        }

        return $this->connections[$queueName];
    }

    public function getQueueCount(QueueNames $queue): int
    {
        try {
            return $this->getConnection($queue)->getMessageCount();
        } catch (TransportException) {
            return 0;
        }
    }
}
