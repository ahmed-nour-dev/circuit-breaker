<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Domain\StateMachine;

use AhmedNour\CircuitBreaker\Domain\CircuitExecution;
use AhmedNour\CircuitBreaker\Domain\CircuitState;

interface CircuitStateHandler
{
    public function supports(CircuitState $state): bool;

    public function execute(CircuitExecution $execution): mixed;
}
