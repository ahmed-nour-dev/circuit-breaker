<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Infrastructure\Failure;

use AhmedNour\CircuitBreaker\Domain\CircuitExecution;
use AhmedNour\CircuitBreaker\Domain\Contracts\InfrastructureFailureHandler;
use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
use Throwable;

final class FailClosedHandler implements InfrastructureFailureHandler
{
    public function handle(
        Throwable $exception,
        CircuitExecution $execution,
    ): never {
        if ($exception instanceof CircuitInfrastructureException) {
            throw $exception;
        }

        throw new CircuitInfrastructureException(
            circuit: $execution->circuit->name,
            previous: $exception,
        );
    }
}
