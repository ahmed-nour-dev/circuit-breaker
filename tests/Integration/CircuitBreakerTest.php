<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\CircuitBreaker\Application\CircuitBreaker;
use App\Modules\CircuitBreaker\Application\CircuitPolicyResolver;
use App\Modules\CircuitBreaker\Domain\CircuitPolicy;
use App\Modules\CircuitBreaker\Domain\CircuitState;
use App\Modules\CircuitBreaker\Domain\Exceptions\CircuitBusyException;
use App\Modules\CircuitBreaker\Domain\Exceptions\CircuitOpenException;
use App\Modules\CircuitBreaker\Domain\StateMachine\CircuitStateMachine;
use App\Modules\CircuitBreaker\Domain\StateMachine\ClosedStateHandler;
use App\Modules\CircuitBreaker\Domain\StateMachine\HalfOpenStateHandler;
use App\Modules\CircuitBreaker\Domain\StateMachine\OpenStateHandler;
use App\Modules\CircuitBreaker\Infrastructure\Failure\HttpFailureClassifier;
use App\Modules\CircuitBreaker\Infrastructure\Lock\RedisCircuitLock;
use App\Modules\CircuitBreaker\Infrastructure\Persistence\RedisCircuitStore;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;
use Tests\Support\FakeClock;

final class CircuitBreakerTest extends TestCase
{
    private Redis $redis;
    private RedisCircuitStore $store;
    private RedisCircuitLock $lock;
    private FakeClock $clock;
    private HttpFailureClassifier $classifier;
    private CircuitPolicyResolver $policyResolver;
    private CircuitBreaker $circuitBreaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = new Redis();
        $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        try {
            $connected = @$this->redis->connect($host, $port, 1.0);
            if (! $connected) {
                $this->markTestSkipped('Redis server is not reachable.');
            }
        } catch (\Throwable) {
            $this->markTestSkipped('Redis server is not reachable.');
        }

        $this->store = new RedisCircuitStore($this->redis, 'integration_circuit:');
        $this->lock = new RedisCircuitLock($this->redis, 'integration_circuit:', ':probe-lock');
        $this->clock = new FakeClock(new DateTimeImmutable('2026-08-17 12:00:00', new DateTimeZone('UTC')));
        $this->classifier = new HttpFailureClassifier();

        $this->policyResolver = new CircuitPolicyResolver(
            providers: [
                'twilio-sms' => [
                    'failure_threshold' => 3,
                    'recovery_timeout' => 60,
                    'half_open_lock_timeout' => 10,
                ],
            ],
            defaultPolicy: new CircuitPolicy(5, 60, 10),
        );

        $closedHandler = new ClosedStateHandler($this->store, $this->classifier, $this->clock);
        $halfOpenHandler = new HalfOpenStateHandler($this->lock, $this->store, $this->classifier, $this->clock);
        $openHandler = new OpenStateHandler($this->store, $this->clock, $halfOpenHandler);

        $stateMachine = new CircuitStateMachine([
            $closedHandler,
            $openHandler,
            $halfOpenHandler,
        ]);

        $this->circuitBreaker = new CircuitBreaker(
            store: $this->store,
            stateMachine: $stateMachine,
            policyResolver: $this->policyResolver,
        );
    }

    protected function tearDown(): void
    {
        $keys = $this->redis->keys('integration_circuit:*');
        if (!empty($keys)) {
            $this->redis->del($keys);
        }

        $this->redis->close();
        parent::tearDown();
    }

    public function test_normal_successful_execution(): void
    {
        $result = $this->circuitBreaker->execute('twilio-sms', function () {
            return 'sms-id-12345';
        });

        $this->assertSame('sms-id-12345', $result);

        $circuit = $this->store->get('twilio-sms');
        $this->assertSame(CircuitState::Closed, $circuit->state);
        $this->assertSame(0, $circuit->failures);
    }

    public function test_complete_circuit_lifecycle_transitions(): void
    {
        $providerName = 'twilio-sms'; // failure_threshold = 3, recovery_timeout = 60s

        // 1. First failure
        try {
            $this->circuitBreaker->execute($providerName, fn () => throw new RuntimeException('Connection timed out'));
        } catch (\Throwable) {}

        $circuit = $this->store->get($providerName);
        $this->assertSame(CircuitState::Closed, $circuit->state);
        $this->assertSame(1, $circuit->failures);

        // 2. Second failure
        try {
            $this->circuitBreaker->execute($providerName, fn () => throw new RuntimeException('503 Service Unavailable'));
        } catch (\Throwable) {}

        $circuit = $this->store->get($providerName);
        $this->assertSame(CircuitState::Closed, $circuit->state);
        $this->assertSame(2, $circuit->failures);

        // 3. Third failure -> TRIPS CIRCUIT OPEN
        try {
            $this->circuitBreaker->execute($providerName, fn () => throw new RuntimeException('Connection refused'));
        } catch (\Throwable) {}

        $circuit = $this->store->get($providerName);
        $this->assertSame(CircuitState::Open, $circuit->state);
        $this->assertSame(3, $circuit->failures);
        $this->assertNotNull($circuit->openedUntil);
        $this->assertSame('2026-08-17 12:01:00', $circuit->openedUntil->format('Y-m-d H:i:s'));

        // 4. While open, subsequent calls fail fast with CircuitOpenException without touching provider
        $invoked = false;
        try {
            $this->circuitBreaker->execute($providerName, function () use (&$invoked) {
                $invoked = true;
                return 'never-reached';
            });
            $this->fail('Expected CircuitOpenException');
        } catch (CircuitOpenException $e) {
            $this->assertSame($providerName, $e->circuitName);
            $this->assertFalse($invoked);
        }

        // 5. Advance clock past recovery timeout (e.g. 12:01:05)
        $this->clock->advanceSeconds(65);

        // 6. Half-Open probe succeeds -> transitions circuit back to CLOSED
        $probeInvoked = false;
        $result = $this->circuitBreaker->execute($providerName, function () use (&$probeInvoked) {
            $probeInvoked = true;
            return 'probe-ok';
        });

        $this->assertTrue($probeInvoked);
        $this->assertSame('probe-ok', $result);

        $recoveredCircuit = $this->store->get($providerName);
        $this->assertSame(CircuitState::Closed, $recoveredCircuit->state);
        $this->assertSame(0, $recoveredCircuit->failures);
        $this->assertNull($recoveredCircuit->openedUntil);
    }

    public function test_probe_failure_reopens_circuit(): void
    {
        $providerName = 'twilio-sms';

        // Trip the circuit to open
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->circuitBreaker->execute($providerName, fn () => throw new RuntimeException('cURL error 28: Operation timeout'));
            } catch (\Throwable) {}
        }

        $circuit = $this->store->get($providerName);
        $this->assertSame(CircuitState::Open, $circuit->state);

        // Advance clock past recovery timeout
        $this->clock->advanceSeconds(65);

        // Probe fails with 500 error
        try {
            $this->circuitBreaker->execute($providerName, fn () => throw new RuntimeException('500 Internal Server Error'));
        } catch (\Throwable) {}

        // Circuit must be re-opened until 12:02:05 (65 + 60 = 125s)
        $reopenedCircuit = $this->store->get($providerName);
        $this->assertSame(CircuitState::Open, $reopenedCircuit->state);
        $this->assertNotNull($reopenedCircuit->openedUntil);
        $this->assertSame('2026-08-17 12:02:05', $reopenedCircuit->openedUntil->format('Y-m-d H:i:s'));
    }

    public function test_concurrent_half_open_probe_throws_circuit_busy_for_second_worker(): void
    {
        $providerName = 'twilio-sms';

        // Trip the circuit to open
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->circuitBreaker->execute($providerName, fn () => throw new RuntimeException('Timeout'));
            } catch (\Throwable) {}
        }

        // Advance clock past recovery timeout
        $this->clock->advanceSeconds(65);

        // Acquire lock manually to simulate Worker 1 running the probe
        $handle = $this->lock->acquire($providerName, 10);
        $this->assertNotNull($handle);

        // Worker 2 attempts execution while lock is held
        $invoked = false;
        $this->expectException(CircuitBusyException::class);
        $this->expectExceptionMessage("Circuit 'twilio-sms' is HALF-OPEN and another worker is currently probing the dependency.");

        try {
            $this->circuitBreaker->execute($providerName, function () use (&$invoked) {
                $invoked = true;
                return 'probe';
            });
        } finally {
            $this->assertFalse($invoked);
            $handle->release();
        }
    }
}
