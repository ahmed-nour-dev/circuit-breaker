<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Integration;

use AhmedNour\CircuitBreaker\Application\CircuitBreaker;
use AhmedNour\CircuitBreaker\Domain\Contracts\CircuitLock;
use AhmedNour\CircuitBreaker\Domain\Contracts\CircuitStore;
use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitBusyException;
use AhmedNour\CircuitBreaker\Providers\CircuitBreakerServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HalfOpenProbeLockWiringTest extends TestCase
{
    private const PREFIX = 'wiring_test:';

    private ?object $connection = null;

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            foreach ($this->connection->keys(self::PREFIX.'*') ?: [] as $key) {
                $this->connection->del($key);
            }
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return ['phpredis' => ['phpredis'], 'predis' => ['predis']];
    }

    #[DataProvider('drivers')]
    public function test_held_probe_lock_yields_busy_and_callback_does_not_run(string $driver): void
    {
        $app = new Container;
        Container::setInstance($app);
        Facade::setFacadeApplication($app);
        $app->instance('config', new ConfigRepository([
            'circuit-breaker' => [
                'redis' => ['connection' => 'default', 'prefix' => self::PREFIX, 'lock_suffix' => ':probe-lock'],
            ],
        ]));

        $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('REDIS_PORT') ?: 6379);
        $app->instance('redis', new RedisManager($app, $driver, [
            'default' => ['host' => $host, 'port' => $port, 'database' => 0],
        ]));

        try {
            $this->connection = $app->make('redis')->connection();
            $this->connection->ping();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis server is not reachable.');
        }

        (new CircuitBreakerServiceProvider($app))->register();

        // Circuit is half-open and another worker already holds the probe lock.
        $store = $app->make(CircuitStore::class);
        $circuit = $store->get('pay');
        $circuit->halfOpen();
        $store->save($circuit);

        $held = $app->make(CircuitLock::class)->acquire('pay', 30);
        $this->assertNotNull($held);

        $runs = 0;
        try {
            $app->make(CircuitBreaker::class)->execute('pay', function () use (&$runs) {
                $runs++;
            });
            $this->fail('Expected CircuitBusyException.');
        } catch (CircuitBusyException) {
            $this->assertSame(0, $runs);
        } finally {
            $held->release();
        }
    }
}
