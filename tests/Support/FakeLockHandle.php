<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Support;

use AhmedNour\CircuitBreaker\Domain\Contracts\LockHandle;
use Closure;

final class FakeLockHandle implements LockHandle
{
    private bool $released = false;

    private bool $expired = false;

    public function __construct(
        private readonly ?Closure $onRelease = null,
    ) {}

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        if ($this->onRelease !== null) {
            ($this->onRelease)();
        }
    }

    public function isHeld(): bool
    {
        return ! $this->released && ! $this->expired;
    }

    /** Simulate the lock TTL elapsing while the holder is still running. */
    public function expire(): void
    {
        $this->expired = true;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }
}
