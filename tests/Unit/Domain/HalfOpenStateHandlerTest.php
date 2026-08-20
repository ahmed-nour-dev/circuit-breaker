<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Unit\Domain;

use AhmedNour\CircuitBreaker\Domain\Circuit;
use AhmedNour\CircuitBreaker\Domain\CircuitExecution;
use AhmedNour\CircuitBreaker\Domain\CircuitPolicy;
use AhmedNour\CircuitBreaker\Domain\CircuitState;
use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitBusyException;
use AhmedNour\CircuitBreaker\Domain\StateMachine\HalfOpenStateHandler;
use AhmedNour\CircuitBreaker\Tests\Support\AlwaysTripFailureClassifier;
use AhmedNour\CircuitBreaker\Tests\Support\FakeClock;
use AhmedNour\CircuitBreaker\Tests\Support\FakeLock;
use AhmedNour\CircuitBreaker\Tests\Support\InMemoryCircuitStore;
use AhmedNour\CircuitBreaker\Tests\Support\NeverTripFailureClassifier;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HalfOpenStateHandlerTest extends TestCase
{
    private InMemoryCircuitStore $store;

    private FakeClock $clock;

    private FakeLock $lock;

    private CircuitPolicy $policy;

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
    }

    public function test_supports_half_open_state_only(): void
    {
        $handler = new HalfOpenStateHandler(
            lock: $this->lock,
            store: $this->store,
            failureClassifier: new AlwaysTripFailureClassifier,
            clock: $this->clock,
        );

        $this->assertTrue($handler->supports(CircuitState::HalfOpen));
        $this->assertFalse($handler->supports(CircuitState::Closed));
        $this->assertFalse($handler->supports(CircuitState::Open));
    }

    public function test_throws_circuit_busy_exception_if_lock_cannot_be_acquired(): void
    {
        $circuit = new Circuit('sms-service', CircuitState::HalfOpen, failures: 3);
        $this->store->save($circuit);

        // Simulate another process holding the lock
        $this->lock->setShouldFailAcquire(true);

        $handler = new HalfOpenStateHandler(
            lock: $this->lock,
            store: $this->store,
            failureClassifier: new AlwaysTripFailureClassifier,
            clock: $this->clock,
        );

        $executed = false;
        $execution = new CircuitExecution(
            circuit: $circuit,
            policy: $this->policy,
            callback: function () use (&$executed) {
                $executed = true;

                return 'probe-result';
            },
        );

        $this->expectException(CircuitBusyException::class);
        $this->expectExceptionMessage("Circuit 'sms-service' is HALF-OPEN and another worker is currently probing the dependency.");

        try {
            $handler->execute($execution);
        } finally {
            $this->assertFalse($executed);
        }
    }

    public function test_successful_probe_closes_circuit_and_releases_lock(): void
    {
        $circuit = new Circuit('sms-service', CircuitState::HalfOpen, failures: 3);
        $this->store->save($circuit);

        $handler = new HalfOpenStateHandler(
            lock: $this->lock,
            store: $this->store,
            failureClassifier: new AlwaysTripFailureClassifier,
            clock: $this->clock,
        );

        $execution = new CircuitExecution(
            circuit: $circuit,
            policy: $this->policy,
            callback: fn () => 'recovered-success',
        );

        $result = $handler->execute($execution);

        $this->assertSame('recovered-success', $result);
        $this->assertSame(CircuitState::Closed, $circuit->state);
        $this->assertSame(0, $circuit->failures);
        $this->assertNull($circuit->openedUntil);

        // Lock must be released
        $this->assertFalse($this->lock->isLocked('sms-service'));

        $persisted = $this->store->get('sms-service');
        $this->assertSame(CircuitState::Closed, $persisted->state);
        $this->assertSame(0, $persisted->failures);
    }

    public function test_failed_probe_with_tripping_error_reopens_circuit_and_releases_lock(): void
    {
        $circuit = new Circuit('sms-service', CircuitState::HalfOpen, failures: 3);
        $this->store->save($circuit);

        $handler = new HalfOpenStateHandler(
            lock: $this->lock,
            store: $this->store,
            failureClassifier: new AlwaysTripFailureClassifier,
            clock: $this->clock,
        );

        $execution = new CircuitExecution(
            circuit: $circuit,
            policy: $this->policy,
            callback: fn () => throw new RuntimeException('504 Gateway Timeout'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('504 Gateway Timeout');

        try {
            $handler->execute($execution);
        } finally {
            $this->assertSame(CircuitState::Open, $circuit->state);
            $this->assertNotNull($circuit->openedUntil);
            $this->assertSame('2026-08-17 12:01:00', $circuit->openedUntil->format('Y-m-d H:i:s'));

            // Lock must be released even after exception
            $this->assertFalse($this->lock->isLocked('sms-service'));

            $persisted = $this->store->get('sms-service');
            $this->assertSame(CircuitState::Open, $persisted->state);
            $this->assertNotNull($persisted->openedUntil);
        }
    }

    public function test_failed_probe_with_non_tripping_error_does_not_reopen_circuit_and_releases_lock(): void
    {
        $circuit = new Circuit('sms-service', CircuitState::HalfOpen, failures: 3);
        $this->store->save($circuit);

        $handler = new HalfOpenStateHandler(
            lock: $this->lock,
            store: $this->store,
            failureClassifier: new NeverTripFailureClassifier,
            clock: $this->clock,
        );

        $execution = new CircuitExecution(
            circuit: $circuit,
            policy: $this->policy,
            callback: fn () => throw new RuntimeException('Domain Logic Error'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Domain Logic Error');

        try {
            $handler->execute($execution);
        } finally {
            // Did not reopen because it was classified as non-tripping
            $this->assertSame(CircuitState::HalfOpen, $circuit->state);

            // Lock must be released
            $this->assertFalse($this->lock->isLocked('sms-service'));
        }
    }
}
