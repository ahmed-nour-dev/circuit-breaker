<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Infrastructure;

use AhmedNour\CircuitBreaker\Infrastructure\Lock\RedisCircuitLock;
use AhmedNour\CircuitBreaker\Infrastructure\Lock\RedisLockHandle;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RedisCircuitLockClientsTest extends TestCase
{
    private const PREFIX = 'clients_test:';

    /** @var list<object> */
    private array $clients = [];

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            foreach ($client->keys(self::PREFIX.'*') ?: [] as $key) {
                $client->del($key);
            }
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function clientTypes(): array
    {
        return [
            'raw \Redis' => ['raw'],
            'Laravel PhpRedisConnection' => ['phpredis'],
            'Laravel PredisConnection' => ['predis'],
        ];
    }

    #[DataProvider('clientTypes')]
    public function test_second_acquire_returns_null_and_release_frees_lock(string $type): void
    {
        $client = $this->makeClient($type);
        $lock = new RedisCircuitLock($client, self::PREFIX);

        $first = $lock->acquire('pay', 10);
        $this->assertInstanceOf(RedisLockHandle::class, $first);

        $this->assertNull($lock->acquire('pay', 10));

        // A stale token must not release the live lock.
        (new RedisLockHandle($client, $first->getKey(), 'stale-token'))->release();
        $this->assertNull($lock->acquire('pay', 10));

        $first->release();

        $again = $lock->acquire('pay', 10);
        $this->assertInstanceOf(RedisLockHandle::class, $again);
        $again->release();
    }

    public function makeClient(string $type): object
    {
        $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        try {
            if ($type === 'raw') {
                $client = new \Redis;
                if (! @$client->connect($host, $port, 1.0)) {
                    $this->markTestSkipped('Redis server is not reachable.');
                }
            } else {
                $manager = new RedisManager(new Container, $type, [
                    'default' => ['host' => $host, 'port' => $port, 'database' => 0],
                ]);
                $client = $manager->connection();
                $client->ping();
            }
        } catch (\Throwable) {
            $this->markTestSkipped('Redis server is not reachable.');
        }

        return $this->clients[] = $client;
    }
}
