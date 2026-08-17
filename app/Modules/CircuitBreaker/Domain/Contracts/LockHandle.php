<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Domain\Contracts;

interface LockHandle
{
    public function release(): void;
}
