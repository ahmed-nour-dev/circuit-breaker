<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Domain\StateMachine;

use AhmedNour\CircuitBreaker\Domain\CircuitExecution;
use AhmedNour\CircuitBreaker\Domain\CircuitState;
use AhmedNour\CircuitBreaker\Domain\Contracts\CircuitStore;
use AhmedNour\CircuitBreaker\Domain\Contracts\Clock;
use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitOpenException;

final class OpenStateHandler implements CircuitStateHandler
{
    public function __construct(
        private readonly CircuitStore $store,
        private readonly Clock $clock,
        private readonly CircuitStateHandler $halfOpenHandler,
    ) {
    }

    public function supports(CircuitState $state): bool
    {
        return $state === CircuitState::Open;
    }

    public function execute(CircuitExecution $execution): mixed
    {
        if (! $execution->circuit->recoveryTimePassed($this->clock->now())) {
            throw new CircuitOpenException($execution->circuit->name);
        }

        $execution->circuit->halfOpen();
        $this->store->save($execution->circuit);

        return $this->halfOpenHandler->execute($execution);
    }
}
