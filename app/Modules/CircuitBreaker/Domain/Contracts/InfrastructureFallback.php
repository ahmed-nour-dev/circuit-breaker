<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Domain\Contracts;

use App\Modules\CircuitBreaker\Domain\CircuitExecution;
use Throwable;

interface InfrastructureFallback
{
    public function execute(
        CircuitExecution $execution,
        Throwable $exception,
    ): mixed;
}
