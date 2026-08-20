<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Application;

use AhmedNour\CircuitBreaker\Domain\Circuit;
use AhmedNour\CircuitBreaker\Domain\CircuitExecution;
use AhmedNour\CircuitBreaker\Domain\Contracts\CircuitStore;
use AhmedNour\CircuitBreaker\Domain\Contracts\InfrastructureFailureHandler;
use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
use AhmedNour\CircuitBreaker\Domain\StateMachine\CircuitStateMachine;
use AhmedNour\CircuitBreaker\Infrastructure\Failure\FailOpenHandler;
use Closure;

final class CircuitBreaker
{
    private readonly InfrastructureFailureHandler $failureHandler;

    public function __construct(
        private readonly CircuitStore $store,
        private readonly CircuitStateMachine $stateMachine,
        private readonly CircuitPolicyResolver $policyResolver,
        ?InfrastructureFailureHandler $failureHandler = null,
    ) {
        $this->failureHandler = $failureHandler ?? new FailOpenHandler;
    }

    public function execute(string $name, Closure $callback): mixed
    {
        try {
            $circuit = $this->store->get($name);

            $policy = $this->policyResolver->resolve($name);

            $execution = new CircuitExecution(
                circuit: $circuit,
                policy: $policy,
                callback: $callback,
            );

            return $this->stateMachine->execute($execution);
        } catch (CircuitInfrastructureException $exception) {
            return $this->failureHandler->handle(
                exception: $exception,
                execution: new CircuitExecution(
                    circuit: new Circuit($name),
                    policy: $this->policyResolver->resolve($name),
                    callback: $callback,
                ),
            );
        }
    }
}
