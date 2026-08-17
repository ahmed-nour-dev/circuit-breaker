<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Providers;

use App\Modules\CircuitBreaker\Application\CircuitBreaker;
use App\Modules\CircuitBreaker\Application\CircuitPolicyResolver;
use App\Modules\CircuitBreaker\Domain\CircuitPolicy;
use App\Modules\CircuitBreaker\Domain\Contracts\CircuitLock;
use App\Modules\CircuitBreaker\Domain\Contracts\CircuitStore;
use App\Modules\CircuitBreaker\Domain\Contracts\Clock;
use App\Modules\CircuitBreaker\Domain\Contracts\FailureClassifier;
use App\Modules\CircuitBreaker\Domain\StateMachine\CircuitStateMachine;
use App\Modules\CircuitBreaker\Domain\StateMachine\ClosedStateHandler;
use App\Modules\CircuitBreaker\Domain\StateMachine\HalfOpenStateHandler;
use App\Modules\CircuitBreaker\Domain\StateMachine\OpenStateHandler;
use App\Modules\CircuitBreaker\Infrastructure\Failure\HttpFailureClassifier;
use App\Modules\CircuitBreaker\Infrastructure\Lock\RedisCircuitLock;
use App\Modules\CircuitBreaker\Infrastructure\Persistence\RedisCircuitStore;
use App\Modules\CircuitBreaker\Infrastructure\Time\SystemClock;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;

final class CircuitBreakerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__, 4) . '/config/circuit-breaker.php',
            'circuit-breaker'
        );

        $this->app->singleton(Clock::class, SystemClock::class);

        $this->app->singleton(FailureClassifier::class, HttpFailureClassifier::class);

        $this->app->singleton(CircuitStore::class, function (Container $app): CircuitStore {
            $redisConnection = $this->resolveRedisClient($app);
            $config = $app->bound('config') ? $app->make('config') : null;
            $prefix = (string) ($config?->get('circuit-breaker.redis.prefix') ?? 'circuit:');

            return new RedisCircuitStore($redisConnection, $prefix);
        });

        $this->app->singleton(CircuitLock::class, function (Container $app): CircuitLock {
            $redisConnection = $this->resolveRedisClient($app);
            $config = $app->bound('config') ? $app->make('config') : null;
            $prefix = (string) ($config?->get('circuit-breaker.redis.prefix') ?? 'circuit:');
            $suffix = (string) ($config?->get('circuit-breaker.redis.lock_suffix') ?? ':probe-lock');

            return new RedisCircuitLock($redisConnection, $prefix, $suffix);
        });

        $this->app->singleton(CircuitPolicyResolver::class, function (Container $app): CircuitPolicyResolver {
            $config = $app->bound('config') ? $app->make('config') : null;
            $providers = (array) ($config?->get('circuit-breaker.providers') ?? []);
            $defaultConfig = (array) ($config?->get('circuit-breaker.default') ?? []);

            $defaultPolicy = new CircuitPolicy(
                failureThreshold: (int) ($defaultConfig['failure_threshold'] ?? 5),
                recoveryTimeout: (int) ($defaultConfig['recovery_timeout'] ?? 60),
                halfOpenLockTimeout: (int) ($defaultConfig['half_open_lock_timeout'] ?? 10),
            );

            return new CircuitPolicyResolver($providers, $defaultPolicy);
        });

        $this->app->singleton(ClosedStateHandler::class, function (Container $app): ClosedStateHandler {
            return new ClosedStateHandler(
                store: $app->make(CircuitStore::class),
                failureClassifier: $app->make(FailureClassifier::class),
                clock: $app->make(Clock::class),
            );
        });

        $this->app->singleton(HalfOpenStateHandler::class, function (Container $app): HalfOpenStateHandler {
            return new HalfOpenStateHandler(
                lock: $app->make(CircuitLock::class),
                store: $app->make(CircuitStore::class),
                failureClassifier: $app->make(FailureClassifier::class),
                clock: $app->make(Clock::class),
            );
        });

        $this->app->singleton(OpenStateHandler::class, function (Container $app): OpenStateHandler {
            return new OpenStateHandler(
                store: $app->make(CircuitStore::class),
                clock: $app->make(Clock::class),
                halfOpenHandler: $app->make(HalfOpenStateHandler::class),
            );
        });

        $this->app->singleton(CircuitStateMachine::class, function (Container $app): CircuitStateMachine {
            return new CircuitStateMachine([
                $app->make(ClosedStateHandler::class),
                $app->make(OpenStateHandler::class),
                $app->make(HalfOpenStateHandler::class),
            ]);
        });

        $this->app->singleton(CircuitBreaker::class, function (Container $app): CircuitBreaker {
            return new CircuitBreaker(
                store: $app->make(CircuitStore::class),
                stateMachine: $app->make(CircuitStateMachine::class),
                policyResolver: $app->make(CircuitPolicyResolver::class),
            );
        });
    }

    public function boot(): void
    {
        if (method_exists($this->app, 'runningInConsole') && $this->app->runningInConsole()) {
            $this->publishes([
                dirname(__DIR__, 4) . '/config/circuit-breaker.php' => function_exists('config_path') ? config_path('circuit-breaker.php') : dirname(__DIR__, 4) . '/config/circuit-breaker.php',
            ], 'circuit-breaker-config');
        }
    }

    private function resolveRedisClient(Container $app): mixed
    {
        $config = $app->bound('config') ? $app->make('config') : null;
        $connectionName = $config?->get('circuit-breaker.redis.connection', 'default') ?? 'default';

        if ($app->bound('redis')) {
            return $app->make('redis')->connection($connectionName);
        }

        // Fallback to native PhpRedis
        $redis = new \Redis();
        $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('REDIS_PORT') ?: 6379);
        $redis->connect($host, $port);

        return $redis;
    }
}
