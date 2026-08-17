<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Infrastructure\Failure;

use App\Modules\CircuitBreaker\Domain\CircuitExecution;
use App\Modules\CircuitBreaker\Domain\Contracts\InfrastructureFailureHandler;
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
