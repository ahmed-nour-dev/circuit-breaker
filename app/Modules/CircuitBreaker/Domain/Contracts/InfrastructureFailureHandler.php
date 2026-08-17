<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Domain\Contracts;

use App\Modules\CircuitBreaker\Domain\CircuitExecution;
use Throwable;

interface InfrastructureFailureHandler
{
    public function handle(
        Throwable $exception,
        CircuitExecution $execution,
    ): mixed;
}
