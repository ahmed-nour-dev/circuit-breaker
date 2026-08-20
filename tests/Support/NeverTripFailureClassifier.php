<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Support;

use AhmedNour\CircuitBreaker\Domain\Contracts\FailureClassifier;
use Throwable;

final class NeverTripFailureClassifier implements FailureClassifier
{
    public function shouldTrip(Throwable $exception): bool
    {
        return false;
    }
}
