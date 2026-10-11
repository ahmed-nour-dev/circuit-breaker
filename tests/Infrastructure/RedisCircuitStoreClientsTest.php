<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Infrastructure;

use AhmedNour\CircuitBreaker\Domain\CircuitState;
use AhmedNour\CircuitBreaker\Infrastructure\Persistence\RedisCircuitStore;
use DateTimeImmutable;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The service provider wires the store to Laravel's Redis manager, so the
 * store must work through both Laravel connection types, not only raw \Redis.
 */
final class RedisCircuitStoreClientsTest extends TestCase
{
    private const PREFIX = 'store_clients_test:';

    private ?object $client = null;

    protected function tearDown(): void
    {
        foreach ($this->client?->keys(self::PREFIX.'*') ?: [] as $key) {
            // phpredis through Laravel returns keys with the connection prefix stripped.
            $this->client->del($key);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return [
            'Laravel PhpRedisConnection' => ['phpredis'],
            'Laravel PredisConnection' => ['predis'],
        ];
    }

    #[DataProvider('drivers')]
    public function test_scripts_work_through_laravel_manager(string $driver): void
    {
        $store = new RedisCircuitStore($this->connect($driver), self::PREFIX);
        $until = new DateTimeImmutable('+60 seconds');

        $this->assertSame(CircuitState::Closed, $store->get('svc')->state);

        $store->recordFailure('svc', 2, $until);
        $circuit = $store->recordFailure('svc', 2, $until);

        $this->assertSame(CircuitState::Open, $circuit->state);
        $this->assertSame(CircuitState::Open, $store->get('svc')->state);

        $this->assertTrue($store->transitionToHalfOpen('svc', new DateTimeImmutable('+2 minutes')));
        $this->assertTrue($store->closeFromHalfOpen('svc'));
        $this->assertSame(CircuitState::Closed, $store->get('svc')->state);

        $store->recordFailure('svc', 5, $until);
        $store->recordSuccess('svc');
        $this->assertSame(0, $store->get('svc')->failures);
    }

    private function connect(string $driver): object
    {
        $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        try {
            $manager = new RedisManager(new Container, $driver, [
                'default' => ['host' => $host, 'port' => $port, 'database' => 0],
            ]);
            $client = $manager->connection();
            $client->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis server is not reachable.');
        }

        return $this->client = $client;
    }
}
