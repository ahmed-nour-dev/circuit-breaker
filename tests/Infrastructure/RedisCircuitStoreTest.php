<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Infrastructure;

use AhmedNour\CircuitBreaker\Domain\Circuit;
use AhmedNour\CircuitBreaker\Domain\CircuitState;
use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
use AhmedNour\CircuitBreaker\Infrastructure\Persistence\RedisCircuitStore;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Redis;

final class RedisCircuitStoreTest extends TestCase
{
    private Redis $redis;

    private RedisCircuitStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = new Redis;
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

        $this->store = new RedisCircuitStore($this->redis, 'test_circuit:');
    }

    protected function tearDown(): void
    {
        // Clean up test keys
        $keys = $this->redis->keys('test_circuit:*');
        if (! empty($keys)) {
            $this->redis->del($keys);
        }

        $this->redis->close();
        parent::tearDown();
    }

    public function test_get_returns_default_closed_circuit_when_key_does_not_exist(): void
    {
        $circuit = $this->store->get('non-existent-provider');

        $this->assertSame('non-existent-provider', $circuit->name);
        $this->assertSame(CircuitState::Closed, $circuit->state);
        $this->assertSame(0, $circuit->failures);
        $this->assertNull($circuit->openedUntil);
    }

    public function test_save_and_get_closed_circuit(): void
    {
        $circuit = new Circuit(
            name: 'twilio-sms',
            state: CircuitState::Closed,
            failures: 2,
            openedUntil: null,
        );

        $this->store->save($circuit);

        $loaded = $this->store->get('twilio-sms');

        $this->assertSame('twilio-sms', $loaded->name);
        $this->assertSame(CircuitState::Closed, $loaded->state);
        $this->assertSame(2, $loaded->failures);
        $this->assertNull($loaded->openedUntil);
    }

    public function test_save_and_get_open_circuit_with_datetime(): void
    {
        $openedUntil = new DateTimeImmutable('2026-08-17 15:30:00.123456', new DateTimeZone('UTC'));
        $circuit = new Circuit(
            name: 'stripe-gateway',
            state: CircuitState::Open,
            failures: 5,
            openedUntil: $openedUntil,
        );

        $this->store->save($circuit);

        $loaded = $this->store->get('stripe-gateway');

        $this->assertSame('stripe-gateway', $loaded->name);
        $this->assertSame(CircuitState::Open, $loaded->state);
        $this->assertSame(5, $loaded->failures);
        $this->assertNotNull($loaded->openedUntil);
        $this->assertSame(
            $openedUntil->format('Y-m-d H:i:s'),
            $loaded->openedUntil->format('Y-m-d H:i:s')
        );
    }

    public function test_save_and_get_half_open_circuit(): void
    {
        $circuit = new Circuit(
            name: 'crm-api',
            state: CircuitState::HalfOpen,
            failures: 3,
            openedUntil: null,
        );

        $this->store->save($circuit);

        $loaded = $this->store->get('crm-api');

        $this->assertSame('crm-api', $loaded->name);
        $this->assertSame(CircuitState::HalfOpen, $loaded->state);
        $this->assertSame(3, $loaded->failures);
    }

    public function test_get_handles_corrupted_redis_value_gracefully(): void
    {
        $this->redis->set('test_circuit:corrupt-circuit', 'not-valid-json');

        $circuit = $this->store->get('corrupt-circuit');

        $this->assertSame('corrupt-circuit', $circuit->name);
        $this->assertSame(CircuitState::Closed, $circuit->state);
        $this->assertSame(0, $circuit->failures);
    }

    public function test_get_wraps_redis_exception_in_circuit_infrastructure_exception(): void
    {
        $failingClient = new class
        {
            public function get(string $key): never
            {
                throw new \RedisException('Connection to Redis failed');
            }
        };

        $store = new RedisCircuitStore($failingClient, 'test_circuit:');

        $this->expectException(CircuitInfrastructureException::class);
        $this->expectExceptionMessage("Circuit 'failing-provider' infrastructure failure.");

        $store->get('failing-provider');
    }

    public function test_save_wraps_redis_exception_in_circuit_infrastructure_exception(): void
    {
        $failingClient = new class
        {
            public function set(string $key, string $value): never
            {
                throw new \RedisException('Write error: Redis cluster node is unavailable');
            }
        };

        $store = new RedisCircuitStore($failingClient, 'test_circuit:');
        $circuit = new Circuit('failing-provider');

        $this->expectException(CircuitInfrastructureException::class);
        $this->expectExceptionMessage("Circuit 'failing-provider' infrastructure failure.");

        $store->save($circuit);
    }
}
