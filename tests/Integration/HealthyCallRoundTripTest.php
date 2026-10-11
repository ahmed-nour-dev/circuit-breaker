<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Integration;

use AhmedNour\CircuitBreaker\Application\CircuitBreaker;
use AhmedNour\CircuitBreaker\Application\CircuitPolicyResolver;
use AhmedNour\CircuitBreaker\Domain\CircuitPolicy;
use AhmedNour\CircuitBreaker\Domain\StateMachine\CircuitStateMachine;
use AhmedNour\CircuitBreaker\Domain\StateMachine\ClosedStateHandler;
use AhmedNour\CircuitBreaker\Infrastructure\Failure\HttpFailureClassifier;
use AhmedNour\CircuitBreaker\Infrastructure\Persistence\RedisCircuitStore;
use AhmedNour\CircuitBreaker\Infrastructure\Redis\RedisConnectionFactory;
use AhmedNour\CircuitBreaker\Infrastructure\Time\SystemClock;
use PHPUnit\Framework\TestCase;

final class HealthyCallRoundTripTest extends TestCase
{
    private RedisConnectionFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new RedisConnectionFactory(
            host: (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            port: (int) (getenv('REDIS_PORT') ?: 6379),
        );

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

    public function test_successful_call_on_healthy_circuit_costs_one_round_trip(): void
    {
        $store = new RedisCircuitStore($this->factory, 'roundtrip_test:');
        $breaker = new CircuitBreaker(
            store: $store,
            stateMachine: new CircuitStateMachine([
                new ClosedStateHandler($store, new HttpFailureClassifier, new SystemClock),
            ]),
            policyResolver: new CircuitPolicyResolver([], new CircuitPolicy(5, 60, 10)),
        );
        $redis = $this->factory->connection();

        $breaker->execute('warmup', fn () => true);

        $before = $this->totalCommands($redis);
        for ($i = 0; $i < 50; $i++) {
            $breaker->execute('rt', fn () => true);
        }
        // The INFO call itself counts as one command.
        $delta = $this->totalCommands($redis) - $before - 1;

        $this->assertSame(50, $delta, 'Expected exactly one Redis command per healthy call.');
    }

    private function totalCommands(\Redis $redis): int
    {
        return (int) $redis->info('stats')['total_commands_processed'];
    }
}
