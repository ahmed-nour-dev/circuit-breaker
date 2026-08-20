<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Domain\Contracts;

interface CircuitLock
{
    public function acquire(string $circuit, int $seconds): ?LockHandle;
}
