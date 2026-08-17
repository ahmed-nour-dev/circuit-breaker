<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Application;

use App\Modules\CircuitBreaker\Domain\Circuit;
use App\Modules\CircuitBreaker\Domain\CircuitExecution;
use App\Modules\CircuitBreaker\Domain\Contracts\CircuitStore;
use App\Modules\CircuitBreaker\Domain\Contracts\InfrastructureFailureHandler;
use App\Modules\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
use App\Modules\CircuitBreaker\Domain\StateMachine\CircuitStateMachine;
use App\Modules\CircuitBreaker\Infrastructure\Failure\FailOpenHandler;
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
        $this->failureHandler = $failureHandler ?? new FailOpenHandler();
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
