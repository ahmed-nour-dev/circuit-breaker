<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Infrastructure\Lock;

use App\Modules\CircuitBreaker\Domain\Contracts\CircuitLock;
use App\Modules\CircuitBreaker\Domain\Contracts\LockHandle;
use Throwable;

final class RedisCircuitLock implements CircuitLock
{
    /**
     * @param mixed $redis Redis client instance
     * @param string $keyPrefix Prefix for lock keys
     * @param string $keySuffix Suffix for probe lock keys
     */
    public function __construct(
        private readonly mixed $redis,
        private readonly string $keyPrefix = 'circuit:',
        private readonly string $keySuffix = ':probe-lock',
    ) {
    }

    public function acquire(string $circuit, int $seconds): ?LockHandle
    {
        $key = $this->formatLockKey($circuit);
        $token = bin2hex(random_bytes(16));

        try {
            $acquired = false;

            if ($this->redis instanceof \Redis) {
                $result = $this->redis->set($key, $token, ['NX', 'EX' => $seconds]);
                $acquired = ($result === true);
            } elseif (is_object($this->redis) && method_exists($this->redis, 'set')) {
                // Laravel Redis connection / Predis
                $result = $this->redis->set($key, $token, 'EX', $seconds, 'NX');
                if ($result === true || $result === 'OK') {
                    $acquired = true;
                } else {
                    // Try alternative argument array syntax if needed
                    $result2 = $this->redis->set($key, $token, ['NX', 'EX' => $seconds]);
                    $acquired = ($result2 === true || $result2 === 'OK');
                }
            }

            if ($acquired) {
                return new RedisLockHandle($this->redis, $key, $token);
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function formatLockKey(string $circuit): string
    {
        return $this->keyPrefix . $circuit . $this->keySuffix;
    }
}
