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
use App\Modules\CircuitBreaker\Infrastructure\Redis\RedisConnectionFactory;
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

        // Stateless services — safe as singletons even under Octane.
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(FailureClassifier::class, HttpFailureClassifier::class);

        // RedisConnectionFactory — singleton per worker, with built-in reconnection.
        // Under Octane, dead connections are detected via PING and transparently
        // reconnected. The disconnect() method is called on Octane flush events.
        $this->app->singleton(RedisConnectionFactory::class, function (Container $app): RedisConnectionFactory {
            $config = $app->bound('config') ? $app->make('config') : null;

            if ($app->bound('redis')) {
                // When Laravel's Redis manager is available, we don't use
                // RedisConnectionFactory — we go through Laravel's connection
                // which is already Octane-aware when configured properly.
                // This branch won't actually construct a factory; the store/lock
                // closures below will detect this and use Laravel's connection.
            }

            return new RedisConnectionFactory(
                host: (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
                port: (int) (getenv('REDIS_PORT') ?: 6379),
                timeout: 2.0,
                password: getenv('REDIS_PASSWORD') ?: null,
                database: (int) (getenv('REDIS_DB') ?: 0),
            );
        });

        // Redis-dependent services use scoped() when available (Laravel 10+).
        // scoped() flushes the resolved instance after each Octane request,
        // preventing stale state. In standard php-fpm, scoped() behaves
        // identically to singleton() — resolved once per request lifecycle.
        $bindMethod = method_exists($this->app, 'scoped') ? 'scoped' : 'singleton';

        $this->app->{$bindMethod}(CircuitStore::class, function (Container $app): CircuitStore {
            $config = $app->bound('config') ? $app->make('config') : null;
            $prefix = (string) ($config?->get('circuit-breaker.redis.prefix') ?? 'circuit:');

            $redis = $this->resolveRedisClient($app);

            return new RedisCircuitStore($redis, $prefix);
        });

        $this->app->{$bindMethod}(CircuitLock::class, function (Container $app): CircuitLock {
            $config = $app->bound('config') ? $app->make('config') : null;
            $prefix = (string) ($config?->get('circuit-breaker.redis.prefix') ?? 'circuit:');
            $suffix = (string) ($config?->get('circuit-breaker.redis.lock_suffix') ?? ':probe-lock');

            $redis = $this->resolveRedisClient($app);

            return new RedisCircuitLock($redis, $prefix, $suffix);
        });

        $this->app->{$bindMethod}(CircuitPolicyResolver::class, function (Container $app): CircuitPolicyResolver {
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

        $this->app->{$bindMethod}(ClosedStateHandler::class, function (Container $app): ClosedStateHandler {
            return new ClosedStateHandler(
                store: $app->make(CircuitStore::class),
                failureClassifier: $app->make(FailureClassifier::class),
                clock: $app->make(Clock::class),
            );
        });

        $this->app->{$bindMethod}(HalfOpenStateHandler::class, function (Container $app): HalfOpenStateHandler {
            return new HalfOpenStateHandler(
                lock: $app->make(CircuitLock::class),
                store: $app->make(CircuitStore::class),
                failureClassifier: $app->make(FailureClassifier::class),
                clock: $app->make(Clock::class),
            );
        });

        $this->app->{$bindMethod}(OpenStateHandler::class, function (Container $app): OpenStateHandler {
            return new OpenStateHandler(
                store: $app->make(CircuitStore::class),
                clock: $app->make(Clock::class),
                halfOpenHandler: $app->make(HalfOpenStateHandler::class),
            );
        });

        $this->app->{$bindMethod}(CircuitStateMachine::class, function (Container $app): CircuitStateMachine {
            return new CircuitStateMachine([
                $app->make(ClosedStateHandler::class),
                $app->make(OpenStateHandler::class),
                $app->make(HalfOpenStateHandler::class),
            ]);
        });

        $this->app->{$bindMethod}(CircuitBreaker::class, function (Container $app): CircuitBreaker {
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

        // Register Octane lifecycle listeners when running under Octane.
        // OperationTerminated fires after each request — we disconnect the
        // Redis factory so the next request gets a fresh/validated connection.
        $this->registerOctaneListeners();
    }

    /**
     * Resolve the Redis client to inject into store/lock.
     *
     * When Laravel's Redis manager is available, use it (already Octane-aware).
     * Otherwise, use our RedisConnectionFactory which has built-in reconnection.
     */
    private function resolveRedisClient(Container $app): mixed
    {
        if ($app->bound('redis')) {
            $config = $app->bound('config') ? $app->make('config') : null;
            $connectionName = $config?->get('circuit-breaker.redis.connection', 'default') ?? 'default';

            return $app->make('redis')->connection($connectionName);
        }

        // Fallback: use reconnecting factory (safe for Octane)
        return $app->make(RedisConnectionFactory::class);
    }

    /**
     * Register Octane lifecycle listeners to clean up Redis connections
     * between requests, preventing stale/dead socket accumulation.
     */
    private function registerOctaneListeners(): void
    {
        // Only register if Octane events exist (package is installed)
        if (! class_exists(\Laravel\Octane\Events\OperationTerminated::class)) {
            return;
        }

        $this->app['events']->listen(
            \Laravel\Octane\Events\OperationTerminated::class,
            function () {
                // Disconnect the factory's pooled connection after each request.
                // On the next request, the factory will reconnect lazily.
                if ($this->app->bound(RedisConnectionFactory::class)) {
                    try {
                        $this->app->make(RedisConnectionFactory::class)->disconnect();
                    } catch (\Throwable) {
                        // Never crash the flush lifecycle
                    }
                }
            }
        );
    }
}
