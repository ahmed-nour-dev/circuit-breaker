<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Infrastructure\Redis;

use Redis;
use Throwable;

/**
 * A lazy, reconnecting Redis connection factory.
 *
 * In standard Laravel (php-fpm), this creates one connection per request lifecycle.
 * In Octane (Swoole/RoadRunner), it detects dead connections and transparently
 * reconnects — preventing the "gone away" / stale connection problem that occurs
 * when a long-lived worker's singleton holds a dropped TCP socket.
 */
final class RedisConnectionFactory
{
    private ?Redis $connection = null;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly float $timeout = 2.0,
        private readonly ?string $password = null,
        private readonly int $database = 0,
    ) {}

    /**
     * Get a healthy Redis connection, reconnecting if the previous one is stale.
     */
    public function connection(): Redis
    {
        if ($this->connection !== null && $this->isAlive($this->connection)) {
            return $this->connection;
        }

        $this->connection = $this->createConnection();

        return $this->connection;
    }

    /**
     * Explicitly disconnect. Called during Octane worker flush to release sockets.
     */
    public function disconnect(): void
    {
        if ($this->connection !== null) {
            try {
                $this->connection->close();
            } catch (Throwable) {
                // Ignore close failures
            }

            $this->connection = null;
        }
    }

    private function createConnection(): Redis
    {
        $redis = new Redis;
        $redis->connect($this->host, $this->port, $this->timeout);

        if ($this->password !== null && $this->password !== '') {
            $redis->auth($this->password);
        }

        if ($this->database !== 0) {
            $redis->select($this->database);
        }

        return $redis;
    }

    private function isAlive(Redis $redis): bool
    {
        try {
            return $redis->ping() === true || $redis->ping() === '+PONG';
        } catch (Throwable) {
            return false;
        }
    }
}
