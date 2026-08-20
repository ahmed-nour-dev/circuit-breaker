<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Infrastructure\Failure;

use AhmedNour\CircuitBreaker\Domain\CircuitExecution;
use AhmedNour\CircuitBreaker\Domain\Contracts\InfrastructureFailureHandler;
use Throwable;

final class FailOpenHandler implements InfrastructureFailureHandler
{
    public function handle(
        Throwable $exception,
        CircuitExecution $execution,
    ): mixed {
        return ($execution->callback)();
    }
}
