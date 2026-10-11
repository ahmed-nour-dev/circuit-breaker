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
                    $this->bestEffort(fn () => $lockHandle->isHeld() && $this->store->reopenFromHalfOpen($execution->circuit->name, $until));
                } else {
                    // The provider answered, so it is reachable: close and rethrow.
                    $execution->circuit->close();
                    $this->bestEffort(fn () => $lockHandle->isHeld() && $this->store->closeFromHalfOpen($execution->circuit->name));
                }

                throw $e;
            }

            $execution->circuit->close();
            $this->bestEffort(fn () => $lockHandle->isHeld() && $this->store->closeFromHalfOpen($execution->circuit->name));

            return $result;
        } finally {
            $lockHandle->release();
        }
    }

    /**
     * A probe that outlived its lock (isHeld() is false) skips its state write: another
     * worker may have started a newer probe, and a late result must not overwrite it.
     *
     * The callback has already run, so a store failure must not surface: it would
     * make the breaker's infrastructure policy run the callback a second time.
     */
    private function bestEffort(callable $record): void
    {
        try {
            $record();
        } catch (CircuitInfrastructureException) {
            // Recording the outcome is best-effort once the callback has run.
        }
    }
}
