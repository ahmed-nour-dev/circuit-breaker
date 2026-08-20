<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Support;

use AhmedNour\CircuitBreaker\Domain\Circuit;
use AhmedNour\CircuitBreaker\Domain\CircuitState;
use AhmedNour\CircuitBreaker\Domain\Contracts\CircuitStore;

final class InMemoryCircuitStore implements CircuitStore
{
    /** @var array<string, Circuit> */
    private array $circuits = [];

    public function get(string $name): Circuit
    {
        if (isset($this->circuits[$name])) {
            // Return a cloned instance to simulate storage retrieval
            $c = $this->circuits[$name];
            return new Circuit($c->name, $c->state, $c->failures, $c->openedUntil);
        }

        return new Circuit(
            name: $name,
            state: CircuitState::Closed,
            failures: 0,
            openedUntil: null,
        );
    }

    public function save(Circuit $circuit): void
    {
        $this->circuits[$circuit->name] = new Circuit(
            $circuit->name,
            $circuit->state,
            $circuit->failures,
            $circuit->openedUntil,
        );
    }

    public function has(string $name): bool
    {
        return isset($this->circuits[$name]);
    }
}
