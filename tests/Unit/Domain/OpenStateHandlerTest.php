<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Unit\Domain;

use AhmedNour\CircuitBreaker\Domain\Circuit;
use AhmedNour\CircuitBreaker\Domain\CircuitExecution;
use AhmedNour\CircuitBreaker\Domain\CircuitPolicy;
use AhmedNour\CircuitBreaker\Domain\CircuitState;
use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitOpenException;
use AhmedNour\CircuitBreaker\Domain\StateMachine\HalfOpenStateHandler;
use AhmedNour\CircuitBreaker\Domain\StateMachine\OpenStateHandler;
use AhmedNour\CircuitBreaker\Tests\Support\AlwaysTripFailureClassifier;
use AhmedNour\CircuitBreaker\Tests\Support\FakeClock;
use AhmedNour\CircuitBreaker\Tests\Support\FakeLock;
use AhmedNour\CircuitBreaker\Tests\Support\InMemoryCircuitStore;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class OpenStateHandlerTest extends TestCase
{
    private InMemoryCircuitStore $store;

    private FakeClock $clock;

    private FakeLock $lock;

    private CircuitPolicy $policy;

    private HalfOpenStateHandler $halfOpenHandler;

    private OpenStateHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new InMemoryCircuitStore;
        $this->clock = new FakeClock(new DateTimeImmutable('2026-08-17 12:00:00', new DateTimeZone('UTC')));
        $this->lock = new FakeLock;
        $this->policy = new CircuitPolicy(
            failureThreshold: 3,
            recoveryTimeout: 60,
            halfOpenLockTimeout: 10,
        );

        $this->halfOpenHandler = new HalfOpenStateHandler(
            lock: $this->lock,
            store: $this->store,
            failureClassifier: new AlwaysTripFailureClassifier,
            clock: $this->clock,
        );

        $this->handler = new OpenStateHandler(
            store: $this->store,
            clock: $this->clock,
            halfOpenHandler: $this->halfOpenHandler,
        );
    }

    public function test_supports_open_state_only(): void
    {
        $this->assertTrue($this->handler->supports(CircuitState::Open));
        $this->assertFalse($this->handler->supports(CircuitState::Closed));
        $this->assertFalse($this->handler->supports(CircuitState::HalfOpen));
    }

    public function test_throws_circuit_open_exception_when_recovery_time_has_not_passed(): void
    {
        $circuit = new Circuit(
            name: 'sms-service',
            state: CircuitState::Open,
            failures: 3,
            openedUntil: new DateTimeImmutable('2026-08-17 12:01:00', new DateTimeZone('UTC')),
        );
        $this->store->save($circuit);

        // Clock is at 12:00:00 (recovery is at 12:01:00)
        $executed = false;
        $execution = new CircuitExecution(
            circuit: $circuit,
            policy: $this->policy,
            callback: function () use (&$executed) {
                $executed = true;

                return 'probe-result';
            },
        );

        $this->expectException(CircuitOpenException::class);
        $this->expectExceptionMessage("Circuit 'sms-service' is OPEN and failing fast.");

        try {
            $this->handler->execute($execution);
        } finally {
            $this->assertFalse($executed, 'Callback must never be executed while circuit is open and unexpired');
            $this->assertSame(CircuitState::Open, $circuit->state);
        }
    }

    public function test_transitions_to_half_open_and_executes_probe_when_recovery_time_passed(): void
    {
        $circuit = new Circuit(
            name: 'sms-service',
            state: CircuitState::Open,
            failures: 3,
            openedUntil: new DateTimeImmutable('2026-08-17 12:01:00', new DateTimeZone('UTC')),
        );
        $this->store->save($circuit);

        // Advance clock past recovery timeout (12:01:05)
        $this->clock->advanceSeconds(65);

        $executed = false;
        $execution = new CircuitExecution(
            circuit: $circuit,
            policy: $this->policy,
            callback: function () use (&$executed) {
                $executed = true;

                return 'probe-success';
            },
        );

        $result = $this->handler->execute($execution);

        $this->assertTrue($executed);
        $this->assertSame('probe-success', $result);
        $this->assertSame(CircuitState::Closed, $circuit->state);
        $this->assertSame(0, $circuit->failures);
    }
}
