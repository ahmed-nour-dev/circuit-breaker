<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Infrastructure\Lock;

use AhmedNour\CircuitBreaker\Domain\Contracts\CircuitLock;
use AhmedNour\CircuitBreaker\Domain\Contracts\LockHandle;
use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
use AhmedNour\CircuitBreaker\Infrastructure\Redis\RedisConnectionFactory;
use Throwable;

final class RedisCircuitLock implements CircuitLock
{
    /**
     * @param  RedisConnectionFactory|mixed  $redis  Redis connection factory or raw client instance
     * @param  string  $keyPrefix  Prefix for lock keys
     * @param  string  $keySuffix  Suffix for probe lock keys
     */
    public function __construct(
        private readonly mixed $redis,
        private readonly string $keyPrefix = 'circuit:',
        private readonly string $keySuffix = ':probe-lock',
    ) {}

    public function acquire(string $circuit, int $seconds): ?LockHandle
    {
        $key = $this->formatLockKey($circuit);
        $token = bin2hex(random_bytes(16));

        try {
            $client = $this->resolveClient();
            $acquired = false;

            if ($client instanceof \Redis) {
                $result = $client->set($key, $token, ['NX', 'EX' => $seconds]);
                $acquired = ($result === true);
            } elseif (is_object($client) && method_exists($client, 'set')) {
                // Laravel Redis connection / Predis
                $result = $client->set($key, $token, 'EX', $seconds, 'NX');
                if ($result === true || $result === 'OK') {
                    $acquired = true;
                } else {
                    // Try alternative argument array syntax if needed
                    $result2 = $client->set($key, $token, ['NX', 'EX' => $seconds]);
                    $acquired = ($result2 === true || $result2 === 'OK');
                }
            }

            if ($acquired) {
                return new RedisLockHandle($client, $key, $token);
            }
        } catch (Throwable $e) {
            throw new CircuitInfrastructureException(
                circuit: $circuit,
                previous: $e,
            );
        }

        return null;
    }

    private function formatLockKey(string $circuit): string
    {
        return $this->keyPrefix.$circuit.$this->keySuffix;
    }

    /**
     * Resolve the underlying Redis client.
     * Supports RedisConnectionFactory (reconnecting) or a raw client instance.
     */
    private function resolveClient(): mixed
    {
        if ($this->redis instanceof RedisConnectionFactory) {
            return $this->redis->connection();
        }

        return $this->redis;
    }
}
