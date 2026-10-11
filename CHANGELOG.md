# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/).

## [2.0.0] - 2026-10-11

### Upgrade guide (v1.0.0 → 2.0.0)

**1. Custom `CircuitStore` implementations must add five methods.**
The state machine now performs every transition through atomic store operations instead of
read-modify-write with `save()`. Implement:

```php
public function recordFailure(string $name, int $threshold, DateTimeImmutable $openUntil): Circuit;
public function recordSuccess(string $name): void;
public function transitionToHalfOpen(string $name, DateTimeImmutable $now): bool;
public function closeFromHalfOpen(string $name): bool;
public function reopenFromHalfOpen(string $name, DateTimeImmutable $until): bool;
```

Each must be atomic and conditional on the stored state (see the interface docblocks).
`RedisCircuitStore` implements them with Lua scripts. If you use the bundled Redis store you
need no code changes.

**2. Custom `LockHandle` implementations must add `isHeld(): bool`.**
It reports whether the handle still owns the lock. A probe that finishes after its lock expired
no longer writes its result to the circuit state. Return `false` when ownership cannot be verified.

**3. Message-text matching is now opt-in.**
`HttpFailureClassifier` no longer trips on exception messages containing `timeout`,
`connection refused`, `cURL error 28`, etc. Exception classes (Guzzle `ConnectException`,
`ServerException`, Laravel `ConnectionException`) and 5xx status codes still trip. Errors that
were only recognised by their message text will stop tripping the circuit. To restore the old
behaviour, bind the classifier yourself:

```php
$this->app->singleton(FailureClassifier::class, fn () => new HttpFailureClassifier(matchMessagePatterns: true));
```

The service provider does not expose this as a config key.

**4. Default `half_open_lock_timeout` changed from 10 s to 60 s.**
The probe lock is never extended, so it must outlive the probe call. If you relied on the old
default, set `half_open_lock_timeout` (or `CIRCUIT_BREAKER_DEFAULT_LOCK_TIMEOUT`) explicitly.
A new optional `call_timeout` policy key makes the package reject a policy whose lock TTL is
not greater than the call timeout (`InvalidArgumentException`).

**5. Packaged config no longer ships sample providers.**
`twilio-sms` and `payment-gateway` entries are now commented out. A published
`config/circuit-breaker.php` is unaffected. If you never published it and relied on those
sample entries, those circuits now use the `default` policy; define them in your own config.

### Redis storage format and upgrading live state

The stored JSON gained an `opened_until_ms` field next to `opened_until`. Keys, key prefix and
lock keys are unchanged. State written by v1.0.0 (no `opened_until_ms`) was tested against
2.0.0 on a real Redis with both phpredis and predis (Laravel `RedisManager`):

| State left by v1.0.0 | Result under 2.0.0 |
| --- | --- |
| closed, failures > 0 | Read correctly; counting continues from the stored count |
| open, recovery time not elapsed | Still rejects calls with `CircuitOpenException` |
| open, recovery time elapsed | One probe runs, circuit closes on success |
| half-open | Probe runs, circuit closes on success |

No migration or key flush is needed. Records are rewritten in the new format on their next
transition. Known limits:

- The atomic open → half-open script treats a record without `opened_until_ms` as eligible
  immediately. The breaker checks `opened_until` in PHP first, so this is only reachable by
  calling `CircuitStore::transitionToHalfOpen()` directly on a legacy record.
- During a rolling deploy, v1.0.0 workers still use non-atomic `save()` and can overwrite
  state written by 2.0.0 workers. The atomicity guarantees apply once every worker runs 2.0.0.
- Rolling back to v1.0.0 is safe: v1 ignores the extra field.

### Fixed

- State transitions are atomic (Redis Lua scripts); concurrent workers no longer lose failure
  counts or overwrite newer state with stale state.
- Only one worker wins the open → half-open transition and probes.
- A non-tripping error (e.g. 4xx) in half-open now closes the circuit and resets the failure
  count, and in closed state resets the count, since it proves the provider is reachable.
- The half-open probe lock is no longer silently lost for slow probes (see upgrade items 2 and 4).
- A busy probe lock is reported correctly with Laravel's phpredis and predis connections
  (`SET NX EX` reply handling; Laravel's `__call` proxy methods are now detected).
- The protected callback runs at most once when Redis fails after the provider call.
- Lock acquisition only converts `\Exception` into infrastructure failures; programming errors
  (`\Error`, `\TypeError`) surface instead of being masked.

### Changed

- CI now covers Laravel 13 and Laravel's Redis manager with both phpredis and predis clients.
- README and ARCHITECTURE aligned with actual behaviour.

[2.0.0]: https://github.com/ahmed-nour-dev/circuit-breaker/compare/v1.0.0...v2.0.0
