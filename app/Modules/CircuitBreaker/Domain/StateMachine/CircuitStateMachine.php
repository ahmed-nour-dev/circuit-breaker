<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Domain\StateMachine;

use App\Modules\CircuitBreaker\Domain\CircuitExecution;
use LogicException;

final class CircuitStateMachine
{
    /**
     * @param iterable<CircuitStateHandler> $handlers
     */
    public function __construct(
        private readonly iterable $handlers,
    ) {
    }

    public function execute(CircuitExecution $execution): mixed
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($execution->circuit->state)) {
                return $handler->execute($execution);
            }
        }

        throw new LogicException(
            sprintf('No handler found for state: %s', $execution->circuit->state->value)
        );
    }
}
