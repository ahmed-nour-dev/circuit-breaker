<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Support;

use AhmedNour\CircuitBreaker\Domain\Contracts\CircuitLock;
use AhmedNour\CircuitBreaker\Domain\Contracts\LockHandle;

final class FakeLock implements CircuitLock
{
    /** @var array<string, bool> */
    private array $locked = [];

    /** @var array<string, FakeLockHandle> */
    private array $handles = [];

    private bool $shouldFailAcquire = false;

    public function acquire(string $circuit, int $seconds): ?LockHandle
    {
        if ($this->shouldFailAcquire || ! empty($this->locked[$circuit])) {
            return null;
        }

        $this->locked[$circuit] = true;

        $handle = new FakeLockHandle(function () use ($circuit) {
            unset($this->locked[$circuit]);
        });
        $this->handles[$circuit] = $handle;

        return $handle;
    }

    /** Simulate every held lock's TTL elapsing: handles stop owning it and it can be re-acquired. */
    public function expireAll(): void
    {
        foreach ($this->handles as $handle) {
            $handle->expire();
        }
        $this->locked = [];
    }

    public function setShouldFailAcquire(bool $fail): void
    {
        $this->shouldFailAcquire = $fail;
    }

    public function isLocked(string $circuit): bool
    {
        return ! empty($this->locked[$circuit]);
    }
}
