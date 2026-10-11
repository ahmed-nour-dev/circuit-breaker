<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Infrastructure\Persistence;

use AhmedNour\CircuitBreaker\Domain\Circuit;
use AhmedNour\CircuitBreaker\Domain\CircuitState;
use AhmedNour\CircuitBreaker\Domain\Contracts\CircuitStore;
use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
use AhmedNour\CircuitBreaker\Infrastructure\Redis\RedisConnectionFactory;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

final class RedisCircuitStore implements CircuitStore
{
    /*
     * Each transition is a single Lua script so the read-modify-write is atomic on the
     * Redis server. The scripts only change state when the stored state still matches
     * the transition's precondition, so a stale worker cannot overwrite a newer state.
     */
    private const LOAD_STATE = <<<'LUA'
local raw = redis.call('GET', KEYS[1])
local state, failures, until_iso, until_ms = 'closed', 0, nil, nil
if raw then
    local ok, d = pcall(cjson.decode, raw)
    if ok and type(d) == 'table' then
        state = d.state or 'closed'
        failures = tonumber(d.failures) or 0
        if type(d.opened_until) == 'string' then until_iso = d.opened_until end
        until_ms = tonumber(d.opened_until_ms)
    end
end
local function put(st, f, iso, ms)
    local v = {state = st, failures = f}
    if iso then v.opened_until = iso; v.opened_until_ms = ms end
    local encoded = cjson.encode(v)
    redis.call('SET', KEYS[1], encoded)
    return encoded
end
LUA;

    private const RECORD_FAILURE = self::LOAD_STATE.<<<'LUA'

if state ~= 'closed' then
    return cjson.encode({state = state, failures = failures, opened_until = until_iso, opened_until_ms = until_ms})
end
failures = failures + 1
if failures >= tonumber(ARGV[1]) then
    return put('open', failures, ARGV[2], tonumber(ARGV[3]))
end
return put('closed', failures)
LUA;

    private const RECORD_SUCCESS = self::LOAD_STATE.<<<'LUA'

if state == 'closed' and failures > 0 then
    put('closed', 0)
    return 1
end
return 0
LUA;

    private const OPEN_TO_HALF_OPEN = self::LOAD_STATE.<<<'LUA'

if state == 'open' and (until_ms == nil or until_ms <= tonumber(ARGV[1])) then
    put('half_open', failures, until_iso, until_ms)
    return 1
end
return 0
LUA;

    private const HALF_OPEN_TO_CLOSED = self::LOAD_STATE.<<<'LUA'

if state == 'half_open' then
    put('closed', 0)
    return 1
end
return 0
LUA;

    private const HALF_OPEN_TO_OPEN = self::LOAD_STATE.<<<'LUA'

if state == 'half_open' then
    put('open', failures, ARGV[1], tonumber(ARGV[2]))
    return 1
end
return 0
LUA;

    /**
     * @param  RedisConnectionFactory|mixed  $redis  Redis connection factory or raw client instance
     * @param  string  $keyPrefix  Prefix for Redis keys
     */
    public function __construct(
        private readonly mixed $redis,
        private readonly string $keyPrefix = 'circuit:',
    ) {}

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

        return $this->hydrate($name, $raw);
    }

    public function save(Circuit $circuit): void
    {
        $key = $this->formatKey($circuit->name);

        $payload = [
            'state' => $circuit->state->value,
            'failures' => $circuit->failures,
            'opened_until' => $circuit->openedUntil?->format(DateTimeInterface::RFC3339_EXTENDED),
            'opened_until_ms' => $circuit->openedUntil !== null ? $this->toMillis($circuit->openedUntil) : null,
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

    public function recordFailure(string $name, int $threshold, DateTimeImmutable $openUntil): Circuit
    {
        $raw = $this->runScript(
            $name,
            self::RECORD_FAILURE,
            [(string) $threshold, $this->formatTime($openUntil), (string) $this->toMillis($openUntil)],
        );

        return $this->hydrate($name, $raw);
    }

    public function recordSuccess(string $name): void
    {
        $this->runScript($name, self::RECORD_SUCCESS);
    }

    public function transitionToHalfOpen(string $name, DateTimeImmutable $now): bool
    {
        return $this->runScript($name, self::OPEN_TO_HALF_OPEN, [(string) $this->toMillis($now)]) === 1;
    }

    public function closeFromHalfOpen(string $name): bool
    {
        return $this->runScript($name, self::HALF_OPEN_TO_CLOSED) === 1;
    }

    public function reopenFromHalfOpen(string $name, DateTimeImmutable $until): bool
    {
        return $this->runScript(
            $name,
            self::HALF_OPEN_TO_OPEN,
            [$this->formatTime($until), (string) $this->toMillis($until)],
        ) === 1;
    }

    /**
     * @param  list<string>  $args
     */
    private function runScript(string $name, string $script, array $args = []): mixed
    {
        $key = $this->formatKey($name);

        try {
            $client = $this->resolveClient();

            if ($client instanceof \Redis) {
                return $client->eval($script, [$key, ...$args], 1);
            }

            // Laravel Redis connection / Predis
            return $client->eval($script, 1, $key, ...$args);
        } catch (Throwable $e) {
            throw new CircuitInfrastructureException(
                circuit: $name,
                previous: $e,
            );
        }
    }

    private function hydrate(string $name, mixed $raw): Circuit
    {
        $closed = new Circuit(
            name: $name,
            state: CircuitState::Closed,
            failures: 0,
            openedUntil: null,
        );

        if (! is_string($raw)) {
            return $closed;
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
            return $closed;
        }
    }

    private function formatTime(DateTimeImmutable $time): string
    {
        return $time->format(DateTimeInterface::RFC3339_EXTENDED);
    }

    private function toMillis(DateTimeImmutable $time): int
    {
        return (int) $time->format('Uv');
    }

    private function formatKey(string $name): string
    {
        return $this->keyPrefix.$name;
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
