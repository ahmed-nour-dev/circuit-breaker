<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Modules\CircuitBreaker\Domain\Circuit;
use App\Modules\CircuitBreaker\Domain\CircuitExecution;
use App\Modules\CircuitBreaker\Domain\CircuitPolicy;
use App\Modules\CircuitBreaker\Domain\CircuitState;
use App\Modules\CircuitBreaker\Domain\StateMachine\ClosedStateHandler;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AlwaysTripFailureClassifier;
use Tests\Support\FakeClock;
use Tests\Support\InMemoryCircuitStore;
use Tests\Support\NeverTripFailureClassifier;

final class ClosedStateHandlerTest extends TestCase
{
    private InMemoryCircuitStore $store;
    private FakeClock $clock;
    private CircuitPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new InMemoryCircuitStore();
        $this->clock = new FakeClock(new DateTimeImmutable('2026-08-17 12:00:00', new DateTimeZone('UTC')));
        $this->policy = new CircuitPolicy(
            failureThreshold: 3,
            recoveryTimeout: 60,
            halfOpenLockTimeout: 10,
        );
    }

    public function test_supports_closed_state_only(): void
    {
        $handler = new ClosedStateHandler(
            store: $this->store,
            failureClassifier: new AlwaysTripFailureClassifier(),
            clock: $this->clock,
        );

        $this->assertTrue($handler->supports(CircuitState::Closed));
        $this->assertFalse($handler->supports(CircuitState::Open));
        $this->assertFalse($handler->supports(CircuitState::HalfOpen));
    }

    public function test_successful_execution_resets_failures_and_persists_closed_state(): void
    {
        $circuit = new Circuit('sms-service', CircuitState::Closed, failures: 2);
        $this->store->save($circuit);

        $handler = new ClosedStateHandler(
            store: $this->store,
            failureClassifier: new AlwaysTripFailureClassifier(),
            clock: $this->clock,
        );

        $execution = new CircuitExecution(
            circuit: $circuit,
            policy: $this->policy,
            callback: fn () => 'sms-sent-successfully',
        );

        $result = $handler->execute($execution);

        $this->assertSame('sms-sent-successfully', $result);
        $this->assertSame(CircuitState::Closed, $circuit->state);
        $this->assertSame(0, $circuit->failures);

        // Check persisted state in store
        $persisted = $this->store->get('sms-service');
        $this->assertSame(CircuitState::Closed, $persisted->state);
        $this->assertSame(0, $persisted->failures);
    }

    public function test_tripping_failure_below_threshold_increments_failures_and_keeps_circuit_closed(): void
    {
        $circuit = new Circuit('sms-service', CircuitState::Closed, failures: 1);
        $this->store->save($circuit);

        $handler = new ClosedStateHandler(
            store: $this->store,
            failureClassifier: new AlwaysTripFailureClassifier(),
            clock: $this->clock,
        );

        $execution = new CircuitExecution(
            circuit: $circuit,
            policy: $this->policy, // threshold = 3
            callback: fn () => throw new RuntimeException('Connection timeout'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection timeout');

        try {
            $handler->execute($execution);
        } finally {
            $this->assertSame(CircuitState::Closed, $circuit->state);
            $this->assertSame(2, $circuit->failures);

            $persisted = $this->store->get('sms-service');
            $this->assertSame(CircuitState::Closed, $persisted->state);
            $this->assertSame(2, $persisted->failures);
        }
    }

    public function test_tripping_failure_reaching_threshold_opens_circuit_with_recovery_timeout(): void
    {
        $circuit = new Circuit('sms-service', CircuitState::Closed, failures: 2);
        $this->store->save($circuit);

        $handler = new ClosedStateHandler(
            store: $this->store,
            failureClassifier: new AlwaysTripFailureClassifier(),
            clock: $this->clock,
        );

        $execution = new CircuitExecution(
            circuit: $circuit,
            policy: $this->policy, // threshold = 3, recoveryTimeout = 60s
            callback: fn () => throw new RuntimeException('503 Service Unavailable'),
        );

        $this->expectException(RuntimeException::class);

        try {
            $handler->execute($execution);
        } finally {
            $this->assertSame(CircuitState::Open, $circuit->state);
            $this->assertSame(3, $circuit->failures);
            $this->assertNotNull($circuit->openedUntil);
            $this->assertSame('2026-08-17 12:01:00', $circuit->openedUntil->format('Y-m-d H:i:s'));

            $persisted = $this->store->get('sms-service');
            $this->assertSame(CircuitState::Open, $persisted->state);
            $this->assertSame(3, $persisted->failures);
            $this->assertNotNull($persisted->openedUntil);
            $this->assertSame('2026-08-17 12:01:00', $persisted->openedUntil->format('Y-m-d H:i:s'));
        }
    }

    public function test_non_tripping_failure_does_not_increment_failures_or_open_circuit(): void
    {
        $circuit = new Circuit('sms-service', CircuitState::Closed, failures: 2);
        $this->store->save($circuit);

        $handler = new ClosedStateHandler(
            store: $this->store,
            failureClassifier: new NeverTripFailureClassifier(),
            clock: $this->clock,
        );

        $execution = new CircuitExecution(
            circuit: $circuit,
            policy: $this->policy,
            callback: fn () => throw new RuntimeException('Validation Error'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Validation Error');

        try {
            $handler->execute($execution);
        } finally {
            $this->assertSame(CircuitState::Closed, $circuit->state);
            $this->assertSame(2, $circuit->failures); // Not incremented

            $persisted = $this->store->get('sms-service');
            $this->assertSame(CircuitState::Closed, $persisted->state);
            $this->assertSame(2, $persisted->failures);
        }
    }
}
