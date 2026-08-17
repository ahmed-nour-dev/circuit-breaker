<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Infrastructure\Failure;

use App\Modules\CircuitBreaker\Domain\CircuitExecution;
use App\Modules\CircuitBreaker\Domain\Contracts\InfrastructureFallback;
use App\Modules\CircuitBreaker\Domain\Contracts\InfrastructureFailureHandler;
use Throwable;

final class FallbackHandler implements InfrastructureFailureHandler
{
    public function __construct(
        private readonly InfrastructureFallback $fallback,
    ) {
    }

    public function handle(
        Throwable $exception,
        CircuitExecution $execution,
    ): mixed {
        return $this->fallback->execute($execution, $exception);
    }
}
