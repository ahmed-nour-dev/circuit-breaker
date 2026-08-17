<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Domain\Contracts;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
