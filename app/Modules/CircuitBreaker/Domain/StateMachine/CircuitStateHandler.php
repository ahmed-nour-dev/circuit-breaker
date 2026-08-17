<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Domain\StateMachine;

use App\Modules\CircuitBreaker\Domain\CircuitExecution;
use App\Modules\CircuitBreaker\Domain\CircuitState;

interface CircuitStateHandler
{
    public function supports(CircuitState $state): bool;

    public function execute(CircuitExecution $execution): mixed;
}
