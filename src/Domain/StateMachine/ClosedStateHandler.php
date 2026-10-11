<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Domain\StateMachine;

use AhmedNour\CircuitBreaker\Domain\CircuitExecution;
use AhmedNour\CircuitBreaker\Domain\CircuitState;
use AhmedNour\CircuitBreaker\Domain\Contracts\CircuitStore;
use AhmedNour\CircuitBreaker\Domain\Contracts\Clock;
use AhmedNour\CircuitBreaker\Domain\Contracts\FailureClassifier;
use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
use Throwable;

final class ClosedStateHandler implements CircuitStateHandler
{
    public function __construct(
        private readonly CircuitStore $store,
        private readonly FailureClassifier $failureClassifier,
        private readonly Clock $clock,
    ) {}

    public function supports(CircuitState $state): bool
    {
        return $state === CircuitState::Closed;
    }

    public function execute(CircuitExecution $execution): mixed
    {
        try {
            $result = ($execution->callback)();
        } catch (Throwable $e) {
            if (! $this->failureClassifier->shouldTrip($e)) {
                // The provider answered, so the failure streak is broken.
                if ($execution->circuit->failures > 0) {
                    $this->bestEffort(fn () => $this->store->recordSuccess($execution->circuit->name));
                    $execution->circuit->close();
                }

                throw $e;
            }

            $until = $this->clock->now()->modify('+'.$execution->policy->recoveryTimeout.' seconds');

            $this->bestEffort(function () use ($execution, $until): void {
                $stored = $this->store->recordFailure(
                    $execution->circuit->name,
                    $execution->policy->failureThreshold,
                    $until,
                );

                $execution->circuit->sync($stored);
            });

            throw $e;
        }

        // Nothing to reset on a healthy circuit, so skip the Redis write.
        if ($execution->circuit->failures > 0) {
            $this->bestEffort(fn () => $this->store->recordSuccess($execution->circuit->name));
            $execution->circuit->close();
        }

        return $result;
    }

    /**
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
