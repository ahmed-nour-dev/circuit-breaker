<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Infrastructure;

use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
use AhmedNour\CircuitBreaker\Infrastructure\Lock\RedisCircuitLock;
use AhmedNour\CircuitBreaker\Infrastructure\Lock\RedisLockHandle;
use PHPUnit\Framework\TestCase;
use Redis;

final class RedisCircuitLockTest extends TestCase
{
    private Redis $redis;

    private RedisCircuitLock $lock;

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

        $this->lock = new RedisCircuitLock($this->redis, 'test_lock:', ':probe-lock');
    }

    protected function tearDown(): void
    {
        $keys = $this->redis->keys('test_lock:*');
        if (! empty($keys)) {
            $this->redis->del($keys);
        }

        $this->redis->close();
        parent::tearDown();
    }

    public function test_acquire_lock_successfully(): void
    {
        $handle = $this->lock->acquire('twilio-sms', 5);

        $this->assertInstanceOf(RedisLockHandle::class, $handle);
        $this->assertNotEmpty($handle->getToken());
        $this->assertSame('test_lock:twilio-sms:probe-lock', $handle->getKey());

        $this->assertTrue($this->redis->exists('test_lock:twilio-sms:probe-lock') > 0);

        $handle->release();
    }

    public function test_cannot_acquire_lock_when_already_held(): void
    {
        $handle1 = $this->lock->acquire('twilio-sms', 10);
        $this->assertNotNull($handle1);

        // Attempt concurrent acquire by another worker
        $handle2 = $this->lock->acquire('twilio-sms', 10);
        $this->assertNull($handle2);

        $handle1->release();

        // Now acquire should succeed
        $handle3 = $this->lock->acquire('twilio-sms', 10);
        $this->assertNotNull($handle3);
        $handle3->release();
    }

    public function test_release_with_mismatched_token_does_not_delete_active_lock(): void
    {
        // Simulate a lock acquired with token A
        $this->redis->set('test_lock:twilio-sms:probe-lock', 'token_A');

        // An old handle with token B attempts release
        $oldHandle = new RedisLockHandle($this->redis, 'test_lock:twilio-sms:probe-lock', 'token_B');
        $oldHandle->release();

        // The active lock with token A must still exist in Redis
        $this->assertSame('token_A', $this->redis->get('test_lock:twilio-sms:probe-lock'));
    }

    public function test_lock_expires_after_ttl(): void
    {
        $handle = $this->lock->acquire('quick-service', 1);
        $this->assertNotNull($handle);

        // Wait 1.2 seconds for TTL expiration
        usleep(1200000);

        $this->assertFalse((bool) $this->redis->exists('test_lock:quick-service:probe-lock'));

        // Another acquire can now succeed
        $handle2 = $this->lock->acquire('quick-service', 5);
        $this->assertNotNull($handle2);
        $handle2->release();
    }

    public function test_acquire_wraps_redis_exception_in_circuit_infrastructure_exception(): void
    {
        $failingClient = new class
        {
            public function set(string $key, string $token, array $options): never
            {
                throw new \RedisException('Connection reset by peer');
            }
        };

        $lock = new RedisCircuitLock($failingClient, 'test_lock:', ':probe-lock');

        $this->expectException(CircuitInfrastructureException::class);
        $this->expectExceptionMessage("Circuit 'failing-circuit' infrastructure failure.");

        $lock->acquire('failing-circuit', 5);
    }
}
