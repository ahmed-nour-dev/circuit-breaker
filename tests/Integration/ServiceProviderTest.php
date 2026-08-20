<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Integration;

use AhmedNour\CircuitBreaker\Application\CircuitBreaker;
use AhmedNour\CircuitBreaker\Application\CircuitPolicyResolver;
use AhmedNour\CircuitBreaker\Domain\Contracts\CircuitLock;
use AhmedNour\CircuitBreaker\Domain\Contracts\CircuitStore;
use AhmedNour\CircuitBreaker\Domain\Contracts\Clock;
use AhmedNour\CircuitBreaker\Domain\Contracts\FailureClassifier;
use AhmedNour\CircuitBreaker\Domain\StateMachine\CircuitStateMachine;
use AhmedNour\CircuitBreaker\Domain\StateMachine\ClosedStateHandler;
use AhmedNour\CircuitBreaker\Domain\StateMachine\HalfOpenStateHandler;
use AhmedNour\CircuitBreaker\Domain\StateMachine\OpenStateHandler;
use AhmedNour\CircuitBreaker\Infrastructure\Redis\RedisConnectionFactory;
use AhmedNour\CircuitBreaker\Providers\CircuitBreakerServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class ServiceProviderTest extends TestCase
{
    private Container $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Container();
        Container::setInstance($this->app);
        Facade::setFacadeApplication($this->app);

        // Bind config repository
        $config = new ConfigRepository([
            'circuit-breaker' => [
                'default' => [
                    'failure_threshold' => 4,
                    'recovery_timeout' => 90,
                    'half_open_lock_timeout' => 15,
                ],
                'providers' => [
                    'custom-provider' => [
                        'failure_threshold' => 2,
                        'recovery_timeout' => 45,
                        'half_open_lock_timeout' => 5,
                    ],
                ],
                'redis' => [
                    'connection' => 'default',
                    'prefix' => 'sp_test_circuit:',
                    'lock_suffix' => ':probe-lock',
                ],
            ],
        ]);

        $this->app->instance('config', $config);
    }

    protected function tearDown(): void
    {
        // Clean up factory connections
        if ($this->app->bound(RedisConnectionFactory::class)) {
            try {
                $this->app->make(RedisConnectionFactory::class)->disconnect();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    public function test_service_provider_registers_and_resolves_all_bindings(): void
    {
        $provider = new CircuitBreakerServiceProvider($this->app);
        $provider->register();

        $this->assertInstanceOf(Clock::class, $this->app->make(Clock::class));
        $this->assertInstanceOf(FailureClassifier::class, $this->app->make(FailureClassifier::class));
        $this->assertInstanceOf(RedisConnectionFactory::class, $this->app->make(RedisConnectionFactory::class));
        $this->assertInstanceOf(CircuitStore::class, $this->app->make(CircuitStore::class));
        $this->assertInstanceOf(CircuitLock::class, $this->app->make(CircuitLock::class));
        $this->assertInstanceOf(CircuitPolicyResolver::class, $this->app->make(CircuitPolicyResolver::class));
        $this->assertInstanceOf(ClosedStateHandler::class, $this->app->make(ClosedStateHandler::class));
        $this->assertInstanceOf(HalfOpenStateHandler::class, $this->app->make(HalfOpenStateHandler::class));
        $this->assertInstanceOf(OpenStateHandler::class, $this->app->make(OpenStateHandler::class));
        $this->assertInstanceOf(CircuitStateMachine::class, $this->app->make(CircuitStateMachine::class));
        $this->assertInstanceOf(\AhmedNour\CircuitBreaker\Domain\Contracts\InfrastructureFailureHandler::class, $this->app->make(\AhmedNour\CircuitBreaker\Domain\Contracts\InfrastructureFailureHandler::class));
        $this->assertInstanceOf(\AhmedNour\CircuitBreaker\Infrastructure\Failure\FailOpenHandler::class, $this->app->make(\AhmedNour\CircuitBreaker\Domain\Contracts\InfrastructureFailureHandler::class));
        $this->assertInstanceOf(CircuitBreaker::class, $this->app->make(CircuitBreaker::class));

        // Test executing through resolved CircuitBreaker
        $circuitBreaker = $this->app->make(CircuitBreaker::class);
        $result = $circuitBreaker->execute('test-resolved', fn () => 'hello-from-container');
        $this->assertSame('hello-from-container', $result);
    }

    public function test_service_provider_resolves_fail_closed_handler_when_configured(): void
    {
        $config = new ConfigRepository([
            'circuit-breaker' => [
                'infrastructure_failure' => [
                    'strategy' => 'fail_closed',
                ],
            ],
        ]);
        $this->app->instance('config', $config);

        $provider = new CircuitBreakerServiceProvider($this->app);
        $provider->register();

        $handler = $this->app->make(\AhmedNour\CircuitBreaker\Domain\Contracts\InfrastructureFailureHandler::class);
        $this->assertInstanceOf(\AhmedNour\CircuitBreaker\Infrastructure\Failure\FailClosedHandler::class, $handler);
    }

    public function test_service_provider_resolves_fallback_handler_when_configured(): void
    {
        $dummyFallback = new class implements \AhmedNour\CircuitBreaker\Domain\Contracts\InfrastructureFallback {
            public function execute(\AhmedNour\CircuitBreaker\Domain\CircuitExecution $execution, \Throwable $exception): mixed
            {
                return 'fallback';
            }
        };

        $this->app->instance(\AhmedNour\CircuitBreaker\Domain\Contracts\InfrastructureFallback::class, $dummyFallback);

        $config = new ConfigRepository([
            'circuit-breaker' => [
                'infrastructure_failure' => [
                    'strategy' => 'fallback',
                ],
            ],
        ]);
        $this->app->instance('config', $config);

        $provider = new CircuitBreakerServiceProvider($this->app);
        $provider->register();

        $handler = $this->app->make(\AhmedNour\CircuitBreaker\Domain\Contracts\InfrastructureFailureHandler::class);
        $this->assertInstanceOf(\AhmedNour\CircuitBreaker\Infrastructure\Failure\FallbackHandler::class, $handler);
    }

    public function test_redis_connection_factory_is_reusable_across_resolves(): void
    {
        $provider = new CircuitBreakerServiceProvider($this->app);
        $provider->register();

        $factory1 = $this->app->make(RedisConnectionFactory::class);
        $factory2 = $this->app->make(RedisConnectionFactory::class);

        // Factory is a singleton — same instance
        $this->assertSame($factory1, $factory2);
    }
}

