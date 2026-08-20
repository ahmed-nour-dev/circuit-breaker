<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Support;

use AhmedNour\CircuitBreaker\Domain\Contracts\LockHandle;
use Closure;

final class FakeLockHandle implements LockHandle
{
    private bool $released = false;

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

    public function isReleased(): bool
    {
        return $this->released;
    }
}
