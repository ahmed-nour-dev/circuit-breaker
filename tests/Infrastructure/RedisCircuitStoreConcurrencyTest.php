<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Infrastructure;

use AhmedNour\CircuitBreaker\Application\CircuitBreaker;
use AhmedNour\CircuitBreaker\Application\CircuitPolicyResolver;
use AhmedNour\CircuitBreaker\Domain\CircuitPolicy;
use AhmedNour\CircuitBreaker\Domain\CircuitState;
use AhmedNour\CircuitBreaker\Domain\StateMachine\CircuitStateMachine;
use AhmedNour\CircuitBreaker\Domain\StateMachine\ClosedStateHandler;
use AhmedNour\CircuitBreaker\Domain\StateMachine\HalfOpenStateHandler;
use AhmedNour\CircuitBreaker\Domain\StateMachine\OpenStateHandler;
use AhmedNour\CircuitBreaker\Infrastructure\Lock\RedisCircuitLock;
use AhmedNour\CircuitBreaker\Infrastructure\Persistence\RedisCircuitStore;
use AhmedNour\CircuitBreaker\Tests\Support\AlwaysTripFailureClassifier;
use AhmedNour\CircuitBreaker\Tests\Support\FakeClock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;

/**
 * Forked workers hammer one circuit in a real Redis to prove transitions are atomic.
 */
final class RedisCircuitStoreConcurrencyTest extends TestCase
{
    private const PREFIX = 'concurrency_circuit:';

    private Redis $redis;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix extensions are required.');
        }

        try {
            $this->redis = $this->connect();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis server is not reachable.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $keys = $this->redis->keys(self::PREFIX.'*');
            if (! empty($keys)) {
                $this->redis->del($keys);
            }

            $this->redis->close();
        }

        parent::tearDown();
    }

    public function test_concurrent_tripping_failures_are_all_counted(): void
    {
        $workers = 40;
        $perWorker = 25;
        $until = $this->now()->modify('+300 seconds');

        $this->runWorkers($workers, function () use ($perWorker, $until): void {
            $store = $this->newStore();
            for ($i = 0; $i < $perWorker; $i++) {
                // Threshold far above the total so the counter itself is what is measured.
                $store->recordFailure('counted', 1_000_000, $until);
            }
        });

        $this->assertSame($workers * $perWorker, $this->newStore()->get('counted')->failures);
    }

    public function test_concurrent_failures_open_the_circuit_at_exactly_the_threshold(): void
    {
        $until = $this->now()->modify('+300 seconds');

        $this->runWorkers(20, function () use ($until): void {
            $store = $this->newStore();
            for ($i = 0; $i < 10; $i++) {
                $store->recordFailure('threshold', 50, $until);
            }
        });

        $circuit = $this->newStore()->get('threshold');
        $this->assertSame(CircuitState::Open, $circuit->state);
        $this->assertSame(50, $circuit->failures, 'Failures after the circuit opened must not be counted.');
    }

    public function test_success_from_a_call_started_before_the_circuit_opened_leaves_it_open(): void
    {
        $store = $this->newStore();
        $store->recordFailure('stale-success', 3, $this->now()->modify('+300 seconds'));

        // Workers read the circuit as closed (failures = 1) and start a slow call.
        $this->runWorkers(8, function (): void {
            $this->newBreaker()->execute('stale-success', function () {
                usleep(400_000);

                return 'ok';
            });
        }, beforeWait: function () use ($store): void {
            usleep(150_000);
            $store->recordFailure('stale-success', 3, $this->now()->modify('+300 seconds'));
            $store->recordFailure('stale-success', 3, $this->now()->modify('+300 seconds'));
        });

        $circuit = $store->get('stale-success');
        $this->assertSame(CircuitState::Open, $circuit->state);
        $this->assertSame(3, $circuit->failures);
    }

    public function test_failure_from_a_call_started_before_the_circuit_opened_does_not_close_or_change_it(): void
    {
        $store = $this->newStore();
        $until = $this->now()->modify('+300 seconds');

        // Workers read the circuit as closed (failures = 0) and then fail slowly.
        $this->runWorkers(8, function (): void {
            try {
                $this->newBreaker()->execute('stale-failure', function () {
                    usleep(400_000);

                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException) {
                // expected
            }
        }, beforeWait: function () use ($store, $until): void {
            usleep(150_000);
            $store->recordFailure('stale-failure', 2, $until);
            $store->recordFailure('stale-failure', 2, $until);
        });

        $circuit = $store->get('stale-failure');
        $this->assertSame(CircuitState::Open, $circuit->state);
        $this->assertSame(2, $circuit->failures);
        $this->assertSame($until->format('Y-m-d H:i:s'), $circuit->openedUntil?->format('Y-m-d H:i:s'));
    }

    public function test_only_one_worker_wins_the_open_to_half_open_transition(): void
    {
        $store = $this->newStore();
        $past = $this->now()->modify('-1 second');
        $store->recordFailure('probe', 1, $past);
        $this->assertSame(CircuitState::Open, $store->get('probe')->state);

        $winners = 'concurrency_winners';
        $this->redis->del($winners);

        $this->runWorkers(16, function () use ($winners): void {
            if ($this->newStore()->transitionToHalfOpen('probe', $this->now())) {
                $this->connect()->incr($winners);
            }
        });

        $this->assertSame('1', $this->redis->get($winners));
        $this->redis->del($winners);
        $this->assertSame(CircuitState::HalfOpen, $store->get('probe')->state);
    }

    public function test_healthy_circuit_success_performs_no_redis_write(): void
    {
        $store = $this->newStore();
        $store->recordFailure('healthy', 5, $this->now()->modify('+300 seconds'));
        $store->recordSuccess('healthy');

        $before = (int) $this->commandCount('set');

        $breaker = $this->newBreaker();
        $breaker->execute('healthy', fn () => 'ok');
        $breaker->execute('healthy', fn () => 'ok');

        $this->assertSame($before, (int) $this->commandCount('set'));
        $this->assertSame(0, $store->get('healthy')->failures);
    }

    private function commandCount(string $command): int
    {
        $stats = $this->redis->info('commandstats');
        $row = $stats['cmdstat_'.$command] ?? '';

        return preg_match('/calls=(\d+)/', (string) $row, $m) ? (int) $m[1] : 0;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function connect(): Redis
    {
        $redis = new Redis;
        $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        if (! @$redis->connect($host, $port, 1.0)) {
            throw new RuntimeException('Redis server is not reachable.');
        }

        return $redis;
    }

    private function newStore(): RedisCircuitStore
    {
        return new RedisCircuitStore($this->connect(), self::PREFIX);
    }

    private function newBreaker(): CircuitBreaker
    {
        $redis = $this->connect();
        $store = new RedisCircuitStore($redis, self::PREFIX);
        $lock = new RedisCircuitLock($redis, self::PREFIX);
        $classifier = new AlwaysTripFailureClassifier;
        $clock = new FakeClock($this->now());

        $halfOpen = new HalfOpenStateHandler($lock, $store, $classifier, $clock);

        return new CircuitBreaker(
            store: $store,
            stateMachine: new CircuitStateMachine([
                new ClosedStateHandler($store, $classifier, $clock),
                new OpenStateHandler($store, $clock, $halfOpen),
                $halfOpen,
            ]),
            policyResolver: new CircuitPolicyResolver(defaultPolicy: new CircuitPolicy(3, 300, 10)),
        );
    }

    /**
     * Fork $count workers, run $work in each, optionally run $beforeWait in the parent, then
     * wait for all of them and fail the test if any worker did not exit cleanly.
     */
    private function runWorkers(int $count, callable $work, ?callable $beforeWait = null): void
    {
        // Close our connection so children never share the parent's socket.
        $this->redis->close();

        $pids = [];
        for ($i = 0; $i < $count; $i++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'Could not fork a worker.');

            if ($pid === 0) {
                $failed = false;
                try {
                    $work();
                } catch (\Throwable $e) {
                    fwrite(STDERR, $e->getMessage().PHP_EOL);
                    $failed = true;
                }

                // Die by signal to skip PHPUnit's shutdown handling; the signal reports the outcome.
                posix_kill(posix_getpid(), $failed ? SIGABRT : SIGKILL);
                exit(1);
            }

            $pids[] = $pid;
        }

        $this->redis = $this->connect();

        if ($beforeWait !== null) {
            $beforeWait();
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(
                pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL,
                'A worker failed.'
            );
        }
    }
}
