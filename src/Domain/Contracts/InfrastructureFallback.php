<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Domain\Contracts;

use AhmedNour\CircuitBreaker\Domain\CircuitExecution;
use Throwable;

interface InfrastructureFallback
{
    public function execute(
        CircuitExecution $execution,
        Throwable $exception,
    ): mixed;
}
