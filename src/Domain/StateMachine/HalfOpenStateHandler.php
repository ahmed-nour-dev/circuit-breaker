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
            $result = ($execution->callback)();

            $execution->circuit->close();
            $this->store->save($execution->circuit);

            return $result;
        } catch (Throwable $e) {
            if ($this->failureClassifier->shouldTrip($e)) {
                $until = $this->clock->now()->modify('+'.$execution->policy->recoveryTimeout.' seconds');
                $execution->circuit->open($until);
                $this->store->save($execution->circuit);
            }

            throw $e;
        } finally {
            $lockHandle->release();
        }
    }
}
