<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Domain\Contracts;

interface LockHandle
{
    public function release(): void;

    /**
     * Whether this handle still owns the lock. False once the lock expired (and
     * possibly was taken by another worker) or when ownership cannot be verified.
     */
    public function isHeld(): bool;
}
