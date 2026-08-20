<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Integration;

use AhmedNour\CircuitBreaker\Application\CircuitBreaker;
use AhmedNour\CircuitBreaker\Application\CircuitPolicyResolver;
use AhmedNour\CircuitBreaker\Domain\Circuit;
use AhmedNour\CircuitBreaker\Domain\CircuitPolicy;
use AhmedNour\CircuitBreaker\Domain\CircuitState;
use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitBusyException;
use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitOpenException;
use AhmedNour\CircuitBreaker\Domain\StateMachine\CircuitStateMachine;
use AhmedNour\CircuitBreaker\Domain\StateMachine\ClosedStateHandler;
use AhmedNour\CircuitBreaker\Domain\StateMachine\HalfOpenStateHandler;
use AhmedNour\CircuitBreaker\Domain\StateMachine\OpenStateHandler;
use AhmedNour\CircuitBreaker\Tests\Support\AlwaysTripFailureClassifier;
use AhmedNour\CircuitBreaker\Tests\Support\FakeClock;
use AhmedNour\CircuitBreaker\Tests\Support\FakeLock;
use AhmedNour\CircuitBreaker\Tests\Support\InMemoryCircuitStore;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class QueueIntegrationExampleTest extends TestCase
{
    private InMemoryCircuitStore $store;

    private FakeClock $clock;

    private FakeLock $lock;

    private CircuitBreaker $circuitBreaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new InMemoryCircuitStore;
        $this->clock = new FakeClock(new DateTimeImmutable('2026-08-17 12:00:00', new DateTimeZone('UTC')));
        $this->lock = new FakeLock;

        $policyResolver = new CircuitPolicyResolver([], new CircuitPolicy(3, 300, 10));
        $classifier = new AlwaysTripFailureClassifier;

        $closed = new ClosedStateHandler($this->store, $classifier, $this->clock);
        $halfOpen = new HalfOpenStateHandler($this->lock, $this->store, $classifier, $this->clock);
        $open = new OpenStateHandler($this->store, $this->clock, $halfOpen);

        $stateMachine = new CircuitStateMachine([$closed, $open, $halfOpen]);

        $this->circuitBreaker = new CircuitBreaker($this->store, $stateMachine, $policyResolver);
    }

    public function test_queue_job_releases_with_delay_when_circuit_is_open(): void
    {
        // Put circuit in Open state
        $circuit = new Circuit(
            name: 'twilio-sms',
            state: CircuitState::Open,
            failures: 3,
            openedUntil: new DateTimeImmutable('2026-08-17 12:05:00', new DateTimeZone('UTC')),
        );
        $this->store->save($circuit);

        $job = new SimulatedSendSmsJob(smsId: 42);
        $job->handle($this->circuitBreaker);

        $this->assertTrue($job->wasReleased);
        $this->assertSame(300, $job->releaseDelaySeconds);
        $this->assertFalse($job->wasProcessed);
    }

    public function test_queue_job_releases_with_short_delay_when_circuit_is_busy(): void
    {
        // Put circuit in HalfOpen state and lock it
        $circuit = new Circuit(
            name: 'twilio-sms',
            state: CircuitState::HalfOpen,
            failures: 3,
        );
        $this->store->save($circuit);
        $this->lock->setShouldFailAcquire(true);

        $job = new SimulatedSendSmsJob(smsId: 42);
        $job->handle($this->circuitBreaker);

        $this->assertTrue($job->wasReleased);
        $this->assertSame(10, $job->releaseDelaySeconds);
        $this->assertFalse($job->wasProcessed);
    }

    public function test_queue_job_processes_successfully_when_circuit_is_closed(): void
    {
        $job = new SimulatedSendSmsJob(smsId: 42);
        $job->handle($this->circuitBreaker);

        $this->assertFalse($job->wasReleased);
        $this->assertTrue($job->wasProcessed);
    }
}

/**
 * Example pattern showing how Laravel Queue jobs interact with CircuitBreaker.
 */
final class SimulatedSendSmsJob
{
    public bool $wasReleased = false;

    public int $releaseDelaySeconds = 0;

    public bool $wasProcessed = false;

    public function __construct(
        public readonly int $smsId,
    ) {}

    public function handle(CircuitBreaker $circuitBreaker): void
    {
        try {
            $circuitBreaker->execute('twilio-sms', function () {
                $this->wasProcessed = true;
            });
        } catch (CircuitOpenException) {
            $this->release(300);
        } catch (CircuitBusyException) {
            $this->release(10);
        }
    }

    public function release(int $delaySeconds): void
    {
        $this->wasReleased = true;
        $this->releaseDelaySeconds = $delaySeconds;
    }
}
