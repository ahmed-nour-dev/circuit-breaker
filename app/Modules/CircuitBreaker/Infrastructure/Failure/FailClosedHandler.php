<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Infrastructure\Failure;

use App\Modules\CircuitBreaker\Domain\CircuitExecution;
use App\Modules\CircuitBreaker\Domain\Contracts\InfrastructureFailureHandler;
use App\Modules\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
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
