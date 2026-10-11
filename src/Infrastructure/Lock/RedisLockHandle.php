<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Infrastructure\Lock;

use AhmedNour\CircuitBreaker\Domain\Contracts\LockHandle;
use Throwable;

final class RedisLockHandle implements LockHandle
{
    private const RELEASE_SCRIPT = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
else
    return 0
end
LUA;

    private bool $released = false;

    public function __construct(
        private readonly mixed $redis,
        private readonly string $key,
        private readonly string $token,
    ) {}

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        try {
            if ($this->redis instanceof \Redis) {
                $this->redis->eval(self::RELEASE_SCRIPT, [$this->key, $this->token], 1);
            } elseif (is_object($this->redis) && is_callable([$this->redis, 'eval'])) {
                // Laravel PhpRedisConnection / PredisConnection and raw Predis client
                $this->redis->eval(self::RELEASE_SCRIPT, 1, $this->key, $this->token);
            }
        } catch (Throwable) {
            // Ignore release failure to prevent crashing application
        } finally {
            $this->released = true;
        }
    }

    public function isHeld(): bool
    {
        if ($this->released) {
            return false;
        }

        try {
            $value = is_object($this->redis) && is_callable([$this->redis, 'get'])
                ? $this->redis->get($this->key)
                : null;

            return (is_string($value) || is_object($value)) && (string) $value === $this->token;
        } catch (Throwable) {
            return false;
        }
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }
}
