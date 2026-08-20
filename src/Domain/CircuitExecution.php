<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Domain;

final readonly class CircuitExecution
{
    public function __construct(
        public Circuit $circuit,
        public CircuitPolicy $policy,
        public \Closure $callback,
    ) {
    }
}
