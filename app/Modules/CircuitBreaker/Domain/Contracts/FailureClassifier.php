<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Domain\Contracts;

interface FailureClassifier
{
    public function shouldTrip(\Throwable $exception): bool;
}
