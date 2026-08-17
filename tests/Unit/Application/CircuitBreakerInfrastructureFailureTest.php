<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Modules\CircuitBreaker\Application\CircuitBreaker;
use App\Modules\CircuitBreaker\Application\CircuitPolicyResolver;
use App\Modules\CircuitBreaker\Domain\Circuit;
use App\Modules\CircuitBreaker\Domain\CircuitExecution;
use App\Modules\CircuitBreaker\Domain\CircuitPolicy;
use App\Modules\CircuitBreaker\Domain\CircuitState;
use App\Modules\CircuitBreaker\Domain\Contracts\CircuitLock;
use App\Modules\CircuitBreaker\Domain\Contracts\CircuitStore;
use App\Modules\CircuitBreaker\Domain\Contracts\InfrastructureFallback;
use App\Modules\CircuitBreaker\Domain\Exceptions\CircuitBusyException;
use App\Modules\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
use App\Modules\CircuitBreaker\Domain\Exceptions\CircuitOpenException;
use App\Modules\CircuitBreaker\Domain\StateMachine\CircuitStateMachine;
use App\Modules\CircuitBreaker\Domain\StateMachine\ClosedStateHandler;
use App\Modules\CircuitBreaker\Domain\StateMachine\HalfOpenStateHandler;
use App\Modules\CircuitBreaker\Domain\StateMachine\OpenStateHandler;
use App\Modules\CircuitBreaker\Infrastructure\Failure\FailClosedHandler;
use App\Modules\CircuitBreaker\Infrastructure\Failure\FailOpenHandler;
use App\Modules\CircuitBreaker\Infrastructure\Failure\FallbackHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AlwaysTripFailureClassifier;
use Tests\Support\FakeClock;
use Tests\Support\FakeLock;
use Tests\Support\InMemoryCircuitStore;
use Throwable;

final class CircuitBreakerInfrastructureFailureTest extends TestCase
{
    private CircuitPolicyResolver $policyResolver;
    private AlwaysTripFailureClassifier $classifier;
    private FakeClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policyResolver = new CircuitPolicyResolver([], new CircuitPolicy(3, 60, 10));
        $this->classifier = new AlwaysTripFailureClassifier();
        $this->clock = new FakeClock();
    }

    private function createBreakerWithStore(
        CircuitStore $store,
        ?CircuitLock $lock = null,
        $failureHandler = null,
    ): CircuitBreaker {
        $lockInstance = $lock ?? new FakeLock();
        $closed = new ClosedStateHandler($store, $this->classifier, $this->clock);
        $halfOpen = new HalfOpenStateHandler($lockInstance, $store, $this->classifier, $this->clock);
        $open = new OpenStateHandler($store, $this->clock, $halfOpen);

        $stateMachine = new CircuitStateMachine([$closed, $open, $halfOpen]);

        return new CircuitBreaker(
            store: $store,
            stateMachine: $stateMachine,
            policyResolver: $this->policyResolver,
            failureHandler: $failureHandler,
        );
    }

    public function test_fail_open_executes_callback_when_store_throws_infrastructure_exception(): void
    {
        $failingStore = new class implements CircuitStore {
            public function get(string $name): Circuit
            {
                throw new CircuitInfrastructureException(circuit: $name);
            }
            public function save(Circuit $circuit): void
            {
                throw new CircuitInfrastructureException(circuit: $circuit->name);
            }
        };

        $breaker = $this->createBreakerWithStore($failingStore, failureHandler: new FailOpenHandler());

        $result = $breaker->execute('twilio-sms', fn () => 'message-sent-despite-redis-down');

        $this->assertSame('message-sent-despite-redis-down', $result);
    }

    public function test_fail_closed_throws_exception_when_store_throws_infrastructure_exception(): void
    {
        $failingStore = new class implements CircuitStore {
            public function get(string $name): Circuit
            {
                throw new CircuitInfrastructureException(circuit: $name);
            }
            public function save(Circuit $circuit): void
            {
                throw new CircuitInfrastructureException(circuit: $circuit->name);
            }
        };

        $breaker = $this->createBreakerWithStore($failingStore, failureHandler: new FailClosedHandler());

        $callbackInvoked = false;
        $this->expectException(CircuitInfrastructureException::class);
        $this->expectExceptionMessage("Circuit 'payment-gateway' infrastructure failure.");

        try {
            $breaker->execute('payment-gateway', function () use (&$callbackInvoked) {
                $callbackInvoked = true;
                return 'payment-processed';
            });
        } finally {
            $this->assertFalse($callbackInvoked);
        }
    }

    public function test_fallback_handler_is_executed_when_store_fails(): void
    {
        $failingStore = new class implements CircuitStore {
            public function get(string $name): Circuit
            {
                throw new CircuitInfrastructureException(circuit: $name);
            }
            public function save(Circuit $circuit): void
            {
                throw new CircuitInfrastructureException(circuit: $circuit->name);
            }
        };

        $fallback = new class implements InfrastructureFallback {
            public bool $executed = false;
            public function execute(CircuitExecution $execution, Throwable $exception): mixed
            {
                $this->executed = true;
                return 'fallback-result-for-' . $execution->circuit->name;
            }
        };

        $breaker = $this->createBreakerWithStore($failingStore, failureHandler: new FallbackHandler($fallback));

        $result = $breaker->execute('crm-api', fn () => 'primary-crm');

        $this->assertTrue($fallback->executed);
        $this->assertSame('fallback-result-for-crm-api', $result);
    }

    public function test_lock_infrastructure_failure_triggers_failure_handler(): void
    {
        $store = new InMemoryCircuitStore();
        $store->save(new Circuit('twilio-sms', CircuitState::HalfOpen, 3));

        $failingLock = new class implements CircuitLock {
            public function acquire(string $circuit, int $seconds): never
            {
                throw new CircuitInfrastructureException(circuit: $circuit, message: 'Redis cluster lock error');
            }
        };

        $breaker = $this->createBreakerWithStore($store, lock: $failingLock, failureHandler: new FailOpenHandler());

        $result = $breaker->execute('twilio-sms', fn () => 'half-open-executed-via-fail-open');

        $this->assertSame('half-open-executed-via-fail-open', $result);
    }

    public function test_lock_busy_is_not_treated_as_infrastructure_failure(): void
    {
        $store = new InMemoryCircuitStore();
        $store->save(new Circuit('twilio-sms', CircuitState::HalfOpen, 3));

        $fakeLock = new FakeLock();
        $fakeLock->setShouldFailAcquire(true); // Simulates another worker holding lock (busy)

        $breaker = $this->createBreakerWithStore($store, lock: $fakeLock, failureHandler: new FailOpenHandler());

        $this->expectException(CircuitBusyException::class);
        $this->expectExceptionMessage("Circuit 'twilio-sms' is HALF-OPEN and another worker is currently probing the dependency.");

        $breaker->execute('twilio-sms', fn () => 'should-not-run');
    }

    public function test_circuit_open_is_not_treated_as_infrastructure_failure(): void
    {
        $store = new InMemoryCircuitStore();
        $store->save(new Circuit(
            name: 'twilio-sms',
            state: CircuitState::Open,
            failures: 3,
            openedUntil: $this->clock->now()->modify('+60 seconds'),
        ));

        $breaker = $this->createBreakerWithStore($store, failureHandler: new FailOpenHandler());

        $this->expectException(CircuitOpenException::class);
        $this->expectExceptionMessage("Circuit 'twilio-sms' is OPEN and failing fast.");

        $breaker->execute('twilio-sms', fn () => 'should-not-run');
    }

    public function test_provider_failure_is_not_caught_by_failure_handler(): void
    {
        $store = new InMemoryCircuitStore();

        $breaker = $this->createBreakerWithStore($store, failureHandler: new FailOpenHandler());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Twilio 500 internal server error');

        $breaker->execute('twilio-sms', fn () => throw new RuntimeException('Twilio 500 internal server error'));
    }
}
