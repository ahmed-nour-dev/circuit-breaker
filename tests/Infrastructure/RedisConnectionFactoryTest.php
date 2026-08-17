<?php

declare(strict_types=1);

namespace Tests\Infrastructure;

use App\Modules\CircuitBreaker\Infrastructure\Redis\RedisConnectionFactory;
use PHPUnit\Framework\TestCase;
use Redis;

final class RedisConnectionFactoryTest extends TestCase
{
    private RedisConnectionFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        $this->factory = new RedisConnectionFactory(host: $host, port: $port);

        // Verify Redis is reachable
        try {
            $this->factory->connection();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis server is not reachable.');
        }
    }

    protected function tearDown(): void
    {
        $this->factory->disconnect();
        parent::tearDown();
    }

    public function test_connection_returns_redis_instance(): void
    {
        $redis = $this->factory->connection();

        $this->assertInstanceOf(Redis::class, $redis);
    }

    public function test_connection_returns_same_instance_on_subsequent_calls(): void
    {
        $first = $this->factory->connection();
        $second = $this->factory->connection();

        $this->assertSame($first, $second);
    }

    public function test_disconnect_and_reconnect_gives_new_instance(): void
    {
        $first = $this->factory->connection();
        $this->factory->disconnect();
        $second = $this->factory->connection();

        $this->assertNotSame($first, $second);
        $this->assertInstanceOf(Redis::class, $second);
    }

    public function test_connection_survives_normal_operations(): void
    {
        $redis = $this->factory->connection();

        $redis->set('circuit_factory_test', 'hello');
        $this->assertSame('hello', $redis->get('circuit_factory_test'));

        $redis->del('circuit_factory_test');
    }

    public function test_reconnects_after_connection_is_closed_externally(): void
    {
        $redis = $this->factory->connection();
        $redis->set('circuit_factory_test_reconnect', 'before');

        // Simulate an external connection drop (e.g. Redis restart, Octane timeout)
        $redis->close();

        // Next call should transparently reconnect
        $reconnected = $this->factory->connection();
        $this->assertInstanceOf(Redis::class, $reconnected);

        // Verify the new connection works
        $reconnected->set('circuit_factory_test_reconnect', 'after');
        $this->assertSame('after', $reconnected->get('circuit_factory_test_reconnect'));

        $reconnected->del('circuit_factory_test_reconnect');
    }

    public function test_disconnect_is_idempotent(): void
    {
        $this->factory->connection();
        $this->factory->disconnect();
        $this->factory->disconnect(); // Should not throw

        // Can reconnect after double disconnect
        $redis = $this->factory->connection();
        $this->assertInstanceOf(Redis::class, $redis);
    }
}
