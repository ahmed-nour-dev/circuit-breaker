<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Integration;

use AhmedNour\CircuitBreaker\Application\CircuitBreaker;
use AhmedNour\CircuitBreaker\Application\CircuitPolicyResolver;
use AhmedNour\CircuitBreaker\Domain\Circuit;
use AhmedNour\CircuitBreaker\Domain\CircuitExecution;
use AhmedNour\CircuitBreaker\Domain\CircuitPolicy;
use AhmedNour\CircuitBreaker\Domain\CircuitState;
use AhmedNour\CircuitBreaker\Domain\Contracts\CircuitLock;
use AhmedNour\CircuitBreaker\Domain\Contracts\CircuitStore;
use AhmedNour\CircuitBreaker\Domain\Contracts\InfrastructureFailureHandler;
use AhmedNour\CircuitBreaker\Domain\Contracts\InfrastructureFallback;
use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitBusyException;
use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
use AhmedNour\CircuitBreaker\Domain\StateMachine\CircuitStateMachine;
use AhmedNour\CircuitBreaker\Domain\StateMachine\ClosedStateHandler;
use AhmedNour\CircuitBreaker\Domain\StateMachine\HalfOpenStateHandler;
use AhmedNour\CircuitBreaker\Domain\StateMachine\OpenStateHandler;
use AhmedNour\CircuitBreaker\Infrastructure\Failure\FailClosedHandler;
use AhmedNour\CircuitBreaker\Infrastructure\Failure\FailOpenHandler;
use AhmedNour\CircuitBreaker\Infrastructure\Failure\FallbackHandler;
use AhmedNour\CircuitBreaker\Infrastructure\Failure\HttpFailureClassifier;
use AhmedNour\CircuitBreaker\Tests\Support\FakeClock;
use AhmedNour\CircuitBreaker\Tests\Support\FakeLock;
use AhmedNour\CircuitBreaker\Tests\Support\InMemoryCircuitStore;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RedisException;
use Throwable;

final class CircuitBreakerInfrastructureFailureIntegrationTest extends TestCase
{
    private FakeClock $clock;

    private HttpFailureClassifier $classifier;

    private CircuitPolicyResolver $policyResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FakeClock(new DateTimeImmutable('2026-08-17 12:00:00', new DateTimeZone('UTC')));
        $this->classifier = new HttpFailureClassifier;
        $this->policyResolver = new CircuitPolicyResolver([], new CircuitPolicy(3, 60, 10));
    }

    private function createCircuitBreaker(
        CircuitStore $store,
        CircuitLock $lock,
        $failureHandler,
    ): CircuitBreaker {
        $closed = new ClosedStateHandler($store, $this->classifier, $this->clock);
        $halfOpen = new HalfOpenStateHandler($lock, $store, $this->classifier, $this->clock);
        $open = new OpenStateHandler($store, $this->clock, $halfOpen);

        $stateMachine = new CircuitStateMachine([$closed, $open, $halfOpen]);

        return new CircuitBreaker(
            store: $store,
            stateMachine: $stateMachine,
            policyResolver: $this->policyResolver,
            failureHandler: $failureHandler,
        );
    }

    /**
     * Requirement 17.1: Fail Open
     * Given: CircuitStore throws CircuitInfrastructureException
     * When: CircuitBreaker executes callback
     * Then: Callback is executed.
     */
    public function test_fail_open_executes_callback_when_store_fails(): void
    {
        $failingStore = new class implements CircuitStore
        {
            public function get(string $name): Circuit
            {
                throw new CircuitInfrastructureException(circuit: $name, previous: new RedisException('Redis connection refused'));
            }

            public function save(Circuit $circuit): void
            {
                throw new CircuitInfrastructureException(circuit: $circuit->name, previous: new RedisException('Redis connection refused'));
            }
        };

        $breaker = $this->createCircuitBreaker(
            store: $failingStore,
            lock: new FakeLock,
            failureHandler: new FailOpenHandler,
        );

        $callbackExecuted = false;
        $result = $breaker->execute('twilio-sms', function () use (&$callbackExecuted) {
            $callbackExecuted = true;

            return 'sms-sent-successfully';
        });

        $this->assertTrue($callbackExecuted);
        $this->assertSame('sms-sent-successfully', $result);
    }

    /**
     * Requirement 17.2: Fail Closed
     * Given: CircuitStore throws CircuitInfrastructureException
     * Then: CircuitInfrastructureException is thrown, Callback is not executed.
     */
    public function test_fail_closed_rejects_operation_when_store_fails(): void
    {
        $failingStore = new class implements CircuitStore
        {
            public function get(string $name): Circuit
            {
                throw new CircuitInfrastructureException(circuit: $name, previous: new RedisException('Cluster down'));
            }

            public function save(Circuit $circuit): void
            {
                throw new CircuitInfrastructureException(circuit: $circuit->name, previous: new RedisException('Cluster down'));
            }
        };

        $breaker = $this->createCircuitBreaker(
            store: $failingStore,
            lock: new FakeLock,
            failureHandler: new FailClosedHandler,
        );

        $callbackExecuted = false;

        $this->expectException(CircuitInfrastructureException::class);
        $this->expectExceptionMessage("Circuit 'payment-gateway' infrastructure failure.");

        try {
            $breaker->execute('payment-gateway', function () use (&$callbackExecuted) {
                $callbackExecuted = true;

                return 'payment-token';
            });
        } finally {
            $this->assertFalse($callbackExecuted);
        }
    }

    /**
     * Requirement 17.3: Fallback
     * Given: Primary CircuitStore fails
     * Then: Fallback strategy is executed.
     */
    public function test_fallback_strategy_is_executed_when_primary_store_fails(): void
    {
        $failingStore = new class implements CircuitStore
        {
            public function get(string $name): Circuit
            {
                throw new CircuitInfrastructureException(circuit: $name, previous: new RedisException('Redis unreachable'));
            }

            public function save(Circuit $circuit): void
            {
                throw new CircuitInfrastructureException(circuit: $circuit->name, previous: new RedisException('Redis unreachable'));
            }
        };

        $fallbackStrategy = new class implements InfrastructureFallback
        {
            public bool $invoked = false;

            public function execute(CircuitExecution $execution, Throwable $exception): mixed
            {
                $this->invoked = true;

                // Fallback executes the callback using an in-memory backup or degraded mode
                return ($execution->callback)().'-fallback-handled';
            }
        };

        $breaker = $this->createCircuitBreaker(
            store: $failingStore,
            lock: new FakeLock,
            failureHandler: new FallbackHandler($fallbackStrategy),
        );

        $result = $breaker->execute('crm-sync', fn () => 'crm-payload');

        $this->assertTrue($fallbackStrategy->invoked);
        $this->assertSame('crm-payload-fallback-handled', $result);
    }

    /**
     * Requirement 17.4: Lock Busy
     * Given: Half-open lock is already owned.
     * Then: CircuitBusyException is thrown. The Infrastructure Failure Handler must not be invoked.
     */
    public function test_lock_busy_throws_circuit_busy_exception_and_does_not_invoke_failure_handler(): void
    {
        $store = new InMemoryCircuitStore;
        $store->save(new Circuit('twilio-sms', CircuitState::HalfOpen, 3));

        $fakeLock = new FakeLock;
        $fakeLock->setShouldFailAcquire(true); // Lock already held by another worker

        $handlerInvoked = false;
        $customFailureHandler = new class($handlerInvoked) implements InfrastructureFailureHandler
        {
            public function __construct(public bool &$invoked) {}

            public function handle(Throwable $exception, CircuitExecution $execution): mixed
            {
                $this->invoked = true;

                return ($execution->callback)();
            }
        };

        $breaker = $this->createCircuitBreaker(
            store: $store,
            lock: $fakeLock,
            failureHandler: $customFailureHandler,
        );

        $this->expectException(CircuitBusyException::class);
        $this->expectExceptionMessage("Circuit 'twilio-sms' is HALF-OPEN and another worker is currently probing the dependency.");

        try {
            $breaker->execute('twilio-sms', fn () => 'probe');
        } finally {
            $this->assertFalse($handlerInvoked, 'Infrastructure failure handler must NOT be invoked on lock busy.');
        }
    }

    /**
     * Requirement 17.5: Lock Infrastructure Failure
     * Given: CircuitLock throws CircuitInfrastructureException.
     * Then: InfrastructureFailureHandler is invoked.
     */
    public function test_lock_infrastructure_failure_invokes_failure_handler(): void
    {
        $store = new InMemoryCircuitStore;
        $store->save(new Circuit('twilio-sms', CircuitState::HalfOpen, 3));

        $failingLock = new class implements CircuitLock
        {
            public function acquire(string $circuit, int $seconds): never
            {
                throw new CircuitInfrastructureException(circuit: $circuit, previous: new RedisException('Lock manager disconnected'));
            }
        };

        $breaker = $this->createCircuitBreaker(
            store: $store,
            lock: $failingLock,
            failureHandler: new FailOpenHandler,
        );

        // Under Fail Open, the probe proceeds despite lock infrastructure outage
        $result = $breaker->execute('twilio-sms', fn () => 'probe-result-via-fail-open');

        $this->assertSame('probe-result-via-fail-open', $result);
    }

    private function saveFailingStore(CircuitState $state): CircuitStore
    {
        return new class($state) implements CircuitStore
        {
            public function __construct(private readonly CircuitState $state) {}

            public function get(string $name): Circuit
            {
                return new Circuit($name, $this->state);
            }

            public function save(Circuit $circuit): void
            {
                throw new CircuitInfrastructureException(circuit: $circuit->name, previous: new RedisException('Redis went away'));
            }
        };
    }

    /**
     * @return array<string, array{0: CircuitState, 1: string}>
     */
    public static function postCallbackScenarios(): array
    {
        $cases = [];
        foreach ([CircuitState::Closed, CircuitState::HalfOpen] as $state) {
            foreach (['fail_open', 'fail_closed', 'fallback'] as $policy) {
                $cases["{$state->value}/{$policy}"] = [$state, $policy];
            }
        }

        return $cases;
    }

    private function handlerFor(string $policy, int &$fallbackCalls): InfrastructureFailureHandler
    {
        return match ($policy) {
            'fail_open' => new FailOpenHandler,
            'fail_closed' => new FailClosedHandler,
            'fallback' => new FallbackHandler(new class($fallbackCalls) implements InfrastructureFallback
            {
                public function __construct(private int &$calls) {}

                public function execute(CircuitExecution $execution, Throwable $exception): mixed
                {
                    $this->calls++;

                    return ($execution->callback)();
                }
            }),
        };
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('postCallbackScenarios')]
    public function test_callback_runs_once_and_result_returned_when_recording_success_fails(CircuitState $state, string $policy): void
    {
        $fallbackCalls = 0;
        $breaker = $this->createCircuitBreaker(
            $this->saveFailingStore($state),
            new FakeLock,
            $this->handlerFor($policy, $fallbackCalls),
        );

        $calls = 0;
        $result = $breaker->execute('payments', function () use (&$calls) {
            $calls++;

            return 'charged';
        });

        $this->assertSame(1, $calls);
        $this->assertSame('charged', $result);
        $this->assertSame(0, $fallbackCalls);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('postCallbackScenarios')]
    public function test_provider_exception_is_rethrown_unchanged_when_recording_failure_fails(CircuitState $state, string $policy): void
    {
        $fallbackCalls = 0;
        $breaker = $this->createCircuitBreaker(
            $this->saveFailingStore($state),
            new FakeLock,
            $this->handlerFor($policy, $fallbackCalls),
        );

        $providerError = new \RuntimeException('provider 500', 500);
        $calls = 0;

        try {
            $breaker->execute('payments', function () use (&$calls, $providerError) {
                $calls++;

                throw $providerError;
            });
            $this->fail('Expected provider exception.');
        } catch (Throwable $e) {
            $this->assertSame($providerError, $e);
        }

        $this->assertSame(1, $calls);
        $this->assertSame(0, $fallbackCalls);
    }
}
