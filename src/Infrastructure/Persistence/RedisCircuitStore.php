<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Infrastructure\Persistence;

use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
use AhmedNour\CircuitBreaker\Domain\Circuit;
use AhmedNour\CircuitBreaker\Domain\CircuitState;
use AhmedNour\CircuitBreaker\Domain\Contracts\CircuitStore;
use AhmedNour\CircuitBreaker\Infrastructure\Redis\RedisConnectionFactory;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

final class RedisCircuitStore implements CircuitStore
{
    /**
     * @param RedisConnectionFactory|mixed $redis Redis connection factory or raw client instance
     * @param string $keyPrefix Prefix for Redis keys
     */
    public function __construct(
        private readonly mixed $redis,
        private readonly string $keyPrefix = 'circuit:',
    ) {
    }

    public function get(string $name): Circuit
    {
        $key = $this->formatKey($name);

        try {
            $raw = $this->resolveClient()->get($key);
        } catch (Throwable $e) {
            throw new CircuitInfrastructureException(
                circuit: $name,
                previous: $e,
            );
        }

        if ($raw === false || $raw === null || ! is_string($raw)) {
            return new Circuit(
                name: $name,
                state: CircuitState::Closed,
                failures: 0,
                openedUntil: null,
            );
        }

        try {
            /** @var array{state?: string, failures?: int, opened_until?: string|null} $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            $state = isset($data['state'])
                ? (CircuitState::tryFrom($data['state']) ?? CircuitState::Closed)
                : CircuitState::Closed;

            $failures = isset($data['failures']) ? (int) $data['failures'] : 0;

            $openedUntil = null;
            if (! empty($data['opened_until']) && is_string($data['opened_until'])) {
                $openedUntil = new DateTimeImmutable($data['opened_until']);
            }

            return new Circuit(
                name: $name,
                state: $state,
                failures: $failures,
                openedUntil: $openedUntil,
            );
        } catch (Throwable) {
            return new Circuit(
                name: $name,
                state: CircuitState::Closed,
                failures: 0,
                openedUntil: null,
            );
        }
    }

    public function save(Circuit $circuit): void
    {
        $key = $this->formatKey($circuit->name);

        $payload = [
            'state' => $circuit->state->value,
            'failures' => $circuit->failures,
            'opened_until' => $circuit->openedUntil?->format(DateTimeInterface::RFC3339_EXTENDED),
        ];

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        try {
            $this->resolveClient()->set($key, $encoded);
        } catch (Throwable $e) {
            throw new CircuitInfrastructureException(
                circuit: $circuit->name,
                previous: $e,
            );
        }
    }

    private function formatKey(string $name): string
    {
        return $this->keyPrefix . $name;
    }

    /**
     * Resolve the underlying Redis client.
     * Supports RedisConnectionFactory (reconnecting) or a raw client instance.
     */
    private function resolveClient(): mixed
    {
        if ($this->redis instanceof RedisConnectionFactory) {
            return $this->redis->connection();
        }

        return $this->redis;
    }
}
