<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Infrastructure\Lock;

use App\Modules\CircuitBreaker\Domain\Contracts\LockHandle;
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
    ) {
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        try {
            if ($this->redis instanceof \Redis) {
                $this->redis->eval(self::RELEASE_SCRIPT, [$this->key, $this->token], 1);
            } elseif (method_exists($this->redis, 'eval')) {
                // Compatible with Laravel Redis connection & Predis
                try {
                    $this->redis->eval(self::RELEASE_SCRIPT, 1, $this->key, $this->token);
                } catch (Throwable) {
                    $this->redis->eval(self::RELEASE_SCRIPT, [$this->key, $this->token], 1);
                }
            }
        } catch (Throwable) {
            // Ignore release failure to prevent crashing application
        } finally {
            $this->released = true;
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
