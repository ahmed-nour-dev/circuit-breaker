<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Application;

use App\Modules\CircuitBreaker\Domain\CircuitExecution;
use App\Modules\CircuitBreaker\Domain\Contracts\CircuitStore;
use App\Modules\CircuitBreaker\Domain\StateMachine\CircuitStateMachine;
use Closure;

final class CircuitBreaker
{
    public function __construct(
        private readonly CircuitStore $store,
        private readonly CircuitStateMachine $stateMachine,
        private readonly CircuitPolicyResolver $policyResolver,
    ) {
    }

    public function execute(string $name, Closure $callback): mixed
    {
        $circuit = $this->store->get($name);

        $policy = $this->policyResolver->resolve($name);

        $execution = new CircuitExecution(
            circuit: $circuit,
            policy: $policy,
            callback: $callback,
        );

        return $this->stateMachine->execute($execution);
    }
}
