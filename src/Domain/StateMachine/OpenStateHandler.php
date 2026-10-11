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
    ) {}

    public function supports(CircuitState $state): bool
    {
        return $state === CircuitState::Open;
    }

    public function execute(CircuitExecution $execution): mixed
    {
        if (! $execution->circuit->recoveryTimePassed($this->clock->now())) {
            throw new CircuitOpenException($execution->circuit->name);
        }

        // Another worker may have moved the circuit on since we read it; only the one
        // that wins the atomic open -> half-open transition gets to probe.
        if (! $this->store->transitionToHalfOpen($execution->circuit->name, $this->clock->now())) {
            $current = $this->store->get($execution->circuit->name);

            if ($current->state !== CircuitState::HalfOpen) {
                throw new CircuitOpenException($execution->circuit->name);
            }
        }

        $execution->circuit->halfOpen();

        return $this->halfOpenHandler->execute($execution);
    }
}
