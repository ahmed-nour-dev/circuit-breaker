<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Domain\Contracts;

interface LockHandle
{
    public function release(): void;
}
