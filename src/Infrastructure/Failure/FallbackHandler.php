<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Infrastructure\Failure;

use AhmedNour\CircuitBreaker\Domain\CircuitExecution;
use AhmedNour\CircuitBreaker\Domain\Contracts\InfrastructureFallback;
use AhmedNour\CircuitBreaker\Domain\Contracts\InfrastructureFailureHandler;
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
