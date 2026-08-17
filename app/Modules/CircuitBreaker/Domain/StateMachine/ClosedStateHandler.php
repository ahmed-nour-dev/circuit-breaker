<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Domain\StateMachine;

use App\Modules\CircuitBreaker\Domain\CircuitExecution;
use App\Modules\CircuitBreaker\Domain\CircuitState;
use App\Modules\CircuitBreaker\Domain\Contracts\CircuitStore;
use App\Modules\CircuitBreaker\Domain\Contracts\Clock;
use App\Modules\CircuitBreaker\Domain\Contracts\FailureClassifier;
use Throwable;

final class ClosedStateHandler implements CircuitStateHandler
{
    public function __construct(
        private readonly CircuitStore $store,
        private readonly FailureClassifier $failureClassifier,
        private readonly Clock $clock,
    ) {
    }

    public function supports(CircuitState $state): bool
    {
        return $state === CircuitState::Closed;
    }

    public function execute(CircuitExecution $execution): mixed
    {
        try {
            $result = ($execution->callback)();

            $execution->circuit->close();
            $this->store->save($execution->circuit);

            return $result;
        } catch (Throwable $e) {
            if (! $this->failureClassifier->shouldTrip($e)) {
                throw $e;
            }

            $execution->circuit->recordFailure();

            if ($execution->circuit->failures >= $execution->policy->failureThreshold) {
                $until = $this->clock->now()->modify('+' . $execution->policy->recoveryTimeout . ' seconds');
                $execution->circuit->open($until);
            }

            $this->store->save($execution->circuit);

            throw $e;
        }
    }
}
