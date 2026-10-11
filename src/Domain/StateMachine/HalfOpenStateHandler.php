<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Domain\StateMachine;

use AhmedNour\CircuitBreaker\Domain\CircuitExecution;
use AhmedNour\CircuitBreaker\Domain\CircuitState;
use AhmedNour\CircuitBreaker\Domain\Contracts\CircuitLock;
use AhmedNour\CircuitBreaker\Domain\Contracts\CircuitStore;
use AhmedNour\CircuitBreaker\Domain\Contracts\Clock;
use AhmedNour\CircuitBreaker\Domain\Contracts\FailureClassifier;
use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitBusyException;
use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
use Throwable;

final class HalfOpenStateHandler implements CircuitStateHandler
{
    public function __construct(
        private readonly CircuitLock $lock,
        private readonly CircuitStore $store,
        private readonly FailureClassifier $failureClassifier,
        private readonly Clock $clock,
    ) {}

    public function supports(CircuitState $state): bool
    {
        return $state === CircuitState::HalfOpen;
    }

    public function execute(CircuitExecution $execution): mixed
    {
        $lockHandle = $this->lock->acquire(
            $execution->circuit->name,
            $execution->policy->halfOpenLockTimeout,
        );

        if ($lockHandle === null) {
            throw new CircuitBusyException($execution->circuit->name);
        }

        try {
            try {
                $result = ($execution->callback)();
            } catch (Throwable $e) {
                if ($this->failureClassifier->shouldTrip($e)) {
                    $until = $this->clock->now()->modify('+'.$execution->policy->recoveryTimeout.' seconds');
                    $execution->circuit->open($until);
                    $this->saveBestEffort($execution);
                }

                throw $e;
            }

            $execution->circuit->close();
            $this->saveBestEffort($execution);

            return $result;
        } finally {
            $lockHandle->release();
        }
    }

    /**
     * The callback has already run, so a store failure must not surface: it would
     * make the breaker's infrastructure policy run the callback a second time.
     */
    private function saveBestEffort(CircuitExecution $execution): void
    {
        try {
            $this->store->save($execution->circuit);
        } catch (CircuitInfrastructureException) {
            // Recording the outcome is best-effort once the callback has run.
        }
    }
}
