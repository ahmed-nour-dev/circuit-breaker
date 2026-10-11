<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Infrastructure\Lock;

use AhmedNour\CircuitBreaker\Domain\Contracts\CircuitLock;
use AhmedNour\CircuitBreaker\Domain\Contracts\LockHandle;
use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
use AhmedNour\CircuitBreaker\Infrastructure\Redis\RedisConnectionFactory;

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

            if ($client instanceof \Redis) {
                $acquired = $client->set($key, $token, ['NX', 'EX' => $seconds]) === true;
            } elseif (is_object($client) && is_callable([$client, 'set'])) {
                // is_callable (not method_exists): Laravel's PredisConnection proxies via __call.
                // Laravel PhpRedisConnection / PredisConnection and raw Predis client share
                // this signature. A falsy/null reply means the key exists: not acquired.
                // Predis replies with a Status object (string-castable), phpredis with true.
                $result = $client->set($key, $token, 'EX', $seconds, 'NX');
                $acquired = $result === true || (is_object($result) || is_string($result)) && (string) $result === 'OK';
            } else {
                $acquired = false;
            }

            if ($acquired) {
                return new RedisLockHandle($client, $key, $token);
            }
        } catch (\Exception $e) {
            // Only connection/Redis errors are infrastructure failures; programming
            // errors (\Error, \TypeError) must surface instead of being masked.
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
