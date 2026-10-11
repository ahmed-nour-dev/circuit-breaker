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

    private float $lastUsedAt = 0.0;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 6379,
        private readonly float $timeout = 2.0,
        private readonly ?string $password = null,
        private readonly int $database = 0,
        private readonly float $revalidateAfter = 5.0,
    ) {}

    /**
     * Get a healthy Redis connection, reconnecting if the previous one is stale.
     */
    public function connection(): Redis
    {
        $now = microtime(true);

        // Only ping a connection that has been idle; a busy one is known to be
        // alive, and a PING per call doubles the round trips on the hot path.
        if ($this->connection !== null
            && ($now - $this->lastUsedAt < $this->revalidateAfter || $this->isAlive($this->connection))) {
            $this->lastUsedAt = $now;

            return $this->connection;
        }

        $this->connection = $this->createConnection();
        $this->lastUsedAt = $now;

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
            $reply = $redis->ping();

            return $reply === true || $reply === '+PONG';
        } catch (Throwable) {
            return false;
        }
    }
}
