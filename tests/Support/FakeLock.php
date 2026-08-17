<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\CircuitBreaker\Domain\Contracts\CircuitLock;
use App\Modules\CircuitBreaker\Domain\Contracts\LockHandle;

final class FakeLock implements CircuitLock
{
    /** @var array<string, bool> */
    private array $locked = [];

    private bool $shouldFailAcquire = false;

    public function acquire(string $circuit, int $seconds): ?LockHandle
    {
        if ($this->shouldFailAcquire || !empty($this->locked[$circuit])) {
            return null;
        }

        $this->locked[$circuit] = true;

        return new FakeLockHandle(function () use ($circuit) {
            unset($this->locked[$circuit]);
        });
    }

    public function setShouldFailAcquire(bool $fail): void
    {
        $this->shouldFailAcquire = $fail;
    }

    public function isLocked(string $circuit): bool
    {
        return !empty($this->locked[$circuit]);
    }
}
