<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\CircuitBreaker\Domain\Contracts\FailureClassifier;
use Throwable;

final class AlwaysTripFailureClassifier implements FailureClassifier
{
    public function shouldTrip(Throwable $exception): bool
    {
        return true;
    }
}
