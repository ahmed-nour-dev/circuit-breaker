<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Domain\Contracts;

use AhmedNour\CircuitBreaker\Domain\CircuitExecution;
use Throwable;

interface InfrastructureFailureHandler
{
    public function handle(
        Throwable $exception,
        CircuitExecution $execution,
    ): mixed;
}
