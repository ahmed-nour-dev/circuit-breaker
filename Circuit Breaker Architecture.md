# Circuit Breaker Architecture

## 1. Purpose

This module provides a reusable Circuit Breaker implementation for external service integrations.

Its purpose is to prevent cascading failures when an external dependency becomes unhealthy.

Examples of protected dependencies include:

- SMS providers
- Payment gateways
- Telecom provisioning APIs
- CRM APIs
- Email providers
- Identity providers
- Third-party REST or GraphQL APIs

The Circuit Breaker wraps an external operation:

```php
$circuitBreaker->execute(
    'twilio-sms',
    fn () => $twilio->send($phone, $message)
);
```

The calling code does not need to know:

- The current circuit state
- How failures are counted
- Where circuit state is stored
- How distributed locking works
- When recovery should be attempted

---

# 2. Goals

The implementation must:

- Work across multiple Laravel application instances.
- Work correctly with multiple Horizon workers.
- Store shared circuit state outside the application process.
- Prevent failure storms against unhealthy providers.
- Fail fast while a provider is known to be unavailable.
- Support configurable policies per provider.
- Support the `Closed`, `Open`, and `HalfOpen` states.
- Ensure only one worker performs a half-open probe.
- Only count relevant provider failures.
- Avoid treating application bugs as provider failures.
- Be independently testable without Redis or Laravel.
- Keep infrastructure concerns isolated from domain logic.

---

# 3. High-Level Architecture

```text
┌───────────────────────────────────────────────────────────┐
│                         Application                       │
│                                                           │
│                     CircuitBreaker                        │
│                           │                               │
│                           ▼                               │
│                     CircuitStore                          │
│                           │                               │
│                           ▼                               │
│                        Circuit                            │
│                           │                               │
│                           ▼                               │
│                  CircuitStateMachine                      │
└───────────────────────────┬───────────────────────────────┘
                            │
             ┌──────────────┼──────────────┐
             │              │              │
             ▼              ▼              ▼
          CLOSED           OPEN         HALF_OPEN
             │              │              │
             │              │              ├── CircuitLock
             │              │              │
             ▼              ▼              ▼
        Call Provider    Fail Fast     Probe Provider
             │
             ▼
      FailureClassifier
             │
             ▼
          Circuit
             │
             ▼
       CircuitStore
             │
             ▼
           Redis
```

The dependency direction is:

```text
Application
    ↓
Domain Contracts
    ↑
Infrastructure
```

The domain layer must not depend on:

- Laravel
- Redis
- Horizon
- Cache facade
- HTTP clients
- Twilio or any specific provider

---

# 4. Module Structure

```text
app/
└── Modules/
    └── CircuitBreaker/
        │
        ├── Domain/
        │   │
        │   ├── Circuit.php
        │   ├── CircuitState.php
        │   ├── CircuitPolicy.php
        │   ├── CircuitExecution.php
        │   │
        │   ├── Contracts/
        │   │   ├── CircuitStore.php
        │   │   ├── CircuitLock.php
        │   │   ├── LockHandle.php
        │   │   ├── FailureClassifier.php
        │   │   └── Clock.php
        │   │
        │   ├── Exceptions/
        │   │   ├── CircuitOpenException.php
        │   │   └── CircuitBusyException.php
        │   │
        │   └── StateMachine/
        │       ├── CircuitStateHandler.php
        │       ├── CircuitStateMachine.php
        │       ├── ClosedStateHandler.php
        │       ├── OpenStateHandler.php
        │       └── HalfOpenStateHandler.php
        │
        ├── Application/
        │   ├── CircuitBreaker.php
        │   └── CircuitPolicyResolver.php
        │
        ├── Infrastructure/
        │   ├── Persistence/
        │   │   └── RedisCircuitStore.php
        │   │
        │   ├── Lock/
        │   │   ├── RedisCircuitLock.php
        │   │   └── RedisLockHandle.php
        │   │
        │   ├── Failure/
        │   │   └── HttpFailureClassifier.php
        │   │
        │   └── Time/
        │       └── SystemClock.php
        │
        └── Providers/
            └── CircuitBreakerServiceProvider.php

config/
└── circuit-breaker.php
```

---

# 5. Domain Model

Each external provider has an independent Circuit.

For example:

```text
twilio-sms

State: OPEN
Failures: 5
Opened Until: 2026-08-17 15:30:00
```

Another provider has its own independent state:

```text
payment-gateway

State: CLOSED
Failures: 1
Opened Until: null
```

The circuit state is represented by the following enum:

```php
namespace App\Modules\CircuitBreaker\Domain;

enum CircuitState: string
{
    case Closed = 'closed';

    case Open = 'open';

    case HalfOpen = 'half_open';
}
```

---

# 6. Circuit Aggregate

The `Circuit` object represents the current state of one protected dependency.

```php
namespace App\Modules\CircuitBreaker\Domain;

final class Circuit
{
    public function __construct(
        public readonly string $name,
        public CircuitState $state = CircuitState::Closed,
        public int $failures = 0,
        public ?\DateTimeImmutable $openedUntil = null,
    ) {
    }

    public function close(): void
    {
        $this->state = CircuitState::Closed;
        $this->failures = 0;
        $this->openedUntil = null;
    }

    public function open(
        \DateTimeImmutable $until
    ): void {
        $this->state = CircuitState::Open;
        $this->openedUntil = $until;
    }

    public function halfOpen(): void
    {
        $this->state = CircuitState::HalfOpen;
    }

    public function recordFailure(): void
    {
        $this->failures++;
    }

    public function recoveryTimePassed(
        \DateTimeImmutable $now
    ): bool {
        return $this->openedUntil === null
            || $now >= $this->openedUntil;
    }
}
```

The Circuit aggregate contains state and domain transitions.

It does not communicate directly with Redis.

---

# 7. Circuit Policy

Each provider may have different failure and recovery requirements.

```php
final readonly class CircuitPolicy
{
    public function __construct(
        public int $failureThreshold,
        public int $recoveryTimeout,
        public int $halfOpenLockTimeout = 10,
    ) {
    }
}
```

Example configuration:

```php
return [

    'providers' => [

        'twilio-sms' => [
            'failure_threshold' => 5,
            'recovery_timeout' => 300,
            'half_open_lock_timeout' => 10,
        ],

        'payment-gateway' => [
            'failure_threshold' => 3,
            'recovery_timeout' => 60,
            'half_open_lock_timeout' => 10,
        ],

    ],

];
```

This allows different providers to have independent policies.

---

# 8. Circuit States

The Circuit Breaker is a state machine with three states.

```text
                    Failure Threshold
         ┌────────────────────────────────┐
         │                                ▼
      ┌───────┐                       ┌────────┐
      │ CLOSED│ ─────────────────────▶│  OPEN  │
      └───────┘                       └────────┘
          ▲                                │
          │                                │
          │ Success                        │ Recovery Timeout
          │                                ▼
          │                           ┌───────────┐
          └───────────────────────────│ HALF_OPEN │
                      Success         └───────────┘
                                           │
                                           │ Failure
                                           ▼
                                         OPEN
```

## CLOSED

The provider is considered healthy.

Requests are allowed.

Failures are counted.

Once the failure threshold is reached:

```text
CLOSED → OPEN
```

---

## OPEN

The provider is considered unavailable.

No provider requests are made.

Requests fail immediately:

```text
CircuitOpenException
```

Once the recovery timeout expires:

```text
OPEN → HALF_OPEN
```

---

## HALF_OPEN

The system performs a probe request.

Only one worker is allowed to perform this probe.

If the probe succeeds:

```text
HALF_OPEN → CLOSED
```

If the probe fails:

```text
HALF_OPEN → OPEN
```

---

# 9. Circuit Store

The state machine should not know how circuit state is persisted.

The contract is:

```php
interface CircuitStore
{
    public function get(string $name): Circuit;

    public function save(Circuit $circuit): void;
}
```

The application works with a `Circuit` object.

Redis is only an infrastructure implementation.

---

# 10. Redis Circuit Store

The Redis implementation is responsible for serializing and restoring Circuit objects.

Example Redis keys:

```text
circuit:twilio-sms
circuit:payment-gateway
```

Example stored value:

```json
{
    "state": "open",
    "failures": 5,
    "opened_until": "2026-08-17T15:30:00+00:00"
}
```

The implementation:

```php
final class RedisCircuitStore implements CircuitStore
{
    public function get(string $name): Circuit
    {
        // Load circuit state from Redis.
    }

    public function save(Circuit $circuit): void
    {
        // Persist circuit state to Redis.
    }
}
```

The rest of the application never accesses Redis directly for circuit state.

---

# 11. Distributed Lock

The Half-Open state requires synchronization.

Without a distributed lock:

```text
Recovery timeout expires.

Worker 1 → Provider
Worker 2 → Provider
Worker 3 → Provider
...
Worker 100 → Provider
```

This defeats the purpose of the half-open probe.

The lock contract is:

```php
interface CircuitLock
{
    public function acquire(
        string $circuit,
        int $seconds
    ): ?LockHandle;
}
```

```php
interface LockHandle
{
    public function release(): void;
}
```

The Redis implementation uses a distributed lock.

```text
circuit:twilio-sms:probe-lock
```

Only the worker that successfully acquires the lock can perform the probe.

---

# 12. Failure Classification

Not every exception represents a provider failure.

This is incorrect:

```php
catch (\Throwable $e) {
    $circuit->recordFailure();
}
```

For example:

```php
$user->phone->number;
```

A null reference or programming error must not cause the Twilio circuit to open.

Instead, failures are classified.

```php
interface FailureClassifier
{
    public function shouldTrip(
        \Throwable $exception
    ): bool;
}
```

Possible failures that may trip the circuit:

- Connection timeout
- DNS failure
- Connection refused
- HTTP 500
- HTTP 502
- HTTP 503
- HTTP 504

Failures that normally should not trip the circuit:

- Validation errors
- Authentication bugs in application code
- Type errors
- Null reference errors
- Invalid application payloads
- Provider business validation errors

The classifier can be provider-specific.

---

# 13. Circuit Execution

A single execution contains the operation and its circuit context.

```php
final readonly class CircuitExecution
{
    public function __construct(
        public Circuit $circuit,
        public CircuitPolicy $policy,
        public \Closure $callback,
    ) {
    }
}
```

Example:

```php
new CircuitExecution(
    circuit: $circuit,
    policy: $policy,
    callback: fn () => $twilio->send($phone, $message),
);
```

---

# 14. State Handler Contract

Each state has its own handler.

```php
interface CircuitStateHandler
{
    public function supports(
        CircuitState $state
    ): bool;

    public function execute(
        CircuitExecution $execution
    ): mixed;
}
```

The state machine selects the appropriate handler.

---

# 15. Closed State

The normal execution path.

```text
                    ┌───────────┐
                    │  CLOSED   │
                    └─────┬─────┘
                          │
                          ▼
                    Call Provider
                          │
                 ┌────────┴────────┐
                 │                 │
                 ▼                 ▼
              Success            Failure
                 │                 │
                 ▼                 ▼
          Reset Failures      Increment Failures
                                   │
                                   ▼
                            Threshold reached?
                              │           │
                             No          Yes
                              │           │
                              ▼           ▼
                           CLOSED        OPEN
```

The handler:

```php
final class ClosedStateHandler
    implements CircuitStateHandler
{
    public function __construct(
        private CircuitStore $store,
        private FailureClassifier $failureClassifier,
        private Clock $clock,
    ) {
    }

    public function supports(
        CircuitState $state
    ): bool {
        return $state === CircuitState::Closed;
    }

    public function execute(
        CircuitExecution $execution
    ): mixed {
        try {
            $result = ($execution->callback)();

            $execution->circuit->close();

            $this->store->save(
                $execution->circuit
            );

            return $result;

        } catch (\Throwable $e) {

            if (
                ! $this->failureClassifier
                    ->shouldTrip($e)
            ) {
                throw $e;
            }

            $execution
                ->circuit
                ->recordFailure();

            if (
                $execution->circuit->failures >=
                $execution->policy->failureThreshold
            ) {
                $until = $this->clock
                    ->now()
                    ->modify(
                        '+' .
                        $execution->policy
                            ->recoveryTimeout .
                        ' seconds'
                    );

                $execution
                    ->circuit
                    ->open($until);
            }

            $this->store->save(
                $execution->circuit
            );

            throw $e;
        }
    }
}
```

---

# 16. Open State

The Open state prevents requests from reaching the provider.

```text
                ┌─────────┐
                │  OPEN   │
                └────┬────┘
                     │
            Recovery timeout?
               │           │
              No          Yes
               │           │
               ▼           ▼
          Fail Fast    HALF_OPEN
```

If the recovery timeout has not expired:

```php
throw new CircuitOpenException($circuit->name);
```

No provider request is made.

---

# 17. Half-Open State

The Half-Open state performs a controlled probe.

```text
                    HALF_OPEN
                        │
                        ▼
                 Acquire Lock
                        │
               ┌────────┴────────┐
               │                 │
          Lock Failed        Lock Acquired
               │                 │
               ▼                 ▼
        CircuitBusyException   Call Provider
                                    │
                           ┌────────┴────────┐
                           │                 │
                        Success            Failure
                           │                 │
                           ▼                 ▼
                        CLOSED              OPEN
```

The lock prevents multiple Horizon workers from probing the provider simultaneously.

---

# 18. State Machine

The State Machine selects the correct handler.

```php
final class CircuitStateMachine
{
    public function __construct(
        private iterable $handlers,
    ) {
    }

    public function execute(
        CircuitExecution $execution
    ): mixed {
        foreach ($this->handlers as $handler) {
            if (
                $handler->supports(
                    $execution->circuit->state
                )
            ) {
                return $handler->execute(
                    $execution
                );
            }
        }

        throw new \LogicException(
            "No handler found for state: " .
            $execution->circuit->state->value
        );
    }
}
```

State selection:

```text
Circuit State
      │
      ▼
CircuitStateMachine
      │
      ├── CLOSED
      │      └── ClosedStateHandler
      │
      ├── OPEN
      │      └── OpenStateHandler
      │
      └── HALF_OPEN
             └── HalfOpenStateHandler
```

---

# 19. Circuit Breaker Application Service

This is the public entry point.

Application code should interact only with this class.

```php
final class CircuitBreaker
{
    public function __construct(
        private CircuitStore $store,
        private CircuitStateMachine $stateMachine,
        private CircuitPolicyResolver $policyResolver,
    ) {
    }

    public function execute(
        string $name,
        \Closure $callback,
    ): mixed {
        $circuit = $this->store->get($name);

        $policy = $this->policyResolver->resolve(
            $name
        );

        $execution = new CircuitExecution(
            circuit: $circuit,
            policy: $policy,
            callback: $callback,
        );

        return $this->stateMachine->execute(
            $execution
        );
    }
}
```

Usage:

```php
$circuitBreaker->execute(
    'twilio-sms',
    fn () => $twilio->send(
        phone: $phone,
        message: $message,
    ),
);
```

---

# 20. Queue Integration

Example queue job:

```php
final class SendSmsJob implements ShouldQueue
{
    public function __construct(
        public int $smsId,
    ) {
    }

    public function handle(
        CircuitBreaker $circuitBreaker,
        TwilioService $twilio,
    ): void {
        try {
            $circuitBreaker->execute(
                'twilio-sms',
                fn () => $twilio->send(
                    $this->smsId
                )
            );

        } catch (CircuitOpenException) {

            $this->release(300);

        } catch (CircuitBusyException) {

            $this->release(10);
        }
    }
}
```

When the circuit is open:

```text
Job
 │
 ▼
Circuit Open
 │
 ▼
Do not call provider
 │
 ▼
Release job
 │
 ▼
Retry later
```

The job does not instantly fail.

---

# 21. Service Container Bindings

Laravel infrastructure bindings belong in the module service provider.

```php
final class CircuitBreakerServiceProvider
    extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            CircuitStore::class,
            RedisCircuitStore::class
        );

        $this->app->bind(
            CircuitLock::class,
            RedisCircuitLock::class
        );

        $this->app->bind(
            Clock::class,
            SystemClock::class
        );

        $this->app->bind(
            FailureClassifier::class,
            HttpFailureClassifier::class
        );
    }
}
```

The Domain depends only on contracts.

Laravel binds those contracts to infrastructure implementations.

---

# 22. Execution Flow

## Normal Success

```text
Job
 │
 ▼
CircuitBreaker
 │
 ▼
Load Circuit
 │
 ▼
State = CLOSED
 │
 ▼
Call Provider
 │
 ▼
Success
 │
 ▼
Reset Failure Count
 │
 ▼
Save Circuit
```

---

## Provider Failure

```text
Job
 │
 ▼
CircuitBreaker
 │
 ▼
State = CLOSED
 │
 ▼
Call Provider
 │
 ▼
503 / Timeout
 │
 ▼
FailureClassifier
 │
 ▼
Count Failure
 │
 ▼
Threshold Reached?
 │
 ├── No → Keep CLOSED
 │
 └── Yes
       │
       ▼
      OPEN
       │
       ▼
Set Recovery Timeout
```

---

## Circuit Open

```text
Job
 │
 ▼
CircuitBreaker
 │
 ▼
State = OPEN
 │
 ▼
Recovery timeout expired?
 │
 ├── No
 │     │
 │     ▼
 │   Fail Fast
 │
 └── Yes
       │
       ▼
    HALF_OPEN
```

---

## Half-Open Recovery

```text
Job
 │
 ▼
HALF_OPEN
 │
 ▼
Acquire Distributed Lock
 │
 ├── Failed
 │      │
 │      ▼
 │   Another worker is probing
 │
 └── Acquired
        │
        ▼
    Call Provider
        │
   ┌────┴────┐
   │         │
Success    Failure
   │         │
   ▼         ▼
CLOSED     OPEN
```

---

# 23. Testing Strategy

The implementation should be tested at multiple levels.

## Domain Unit Tests

Test:

- New circuit starts closed.
- Successful execution resets failures.
- Failure increments failure count.
- Threshold opens the circuit.
- Open circuit fails fast.
- Recovery timeout allows half-open transition.
- Successful probe closes the circuit.
- Failed probe reopens the circuit.

These tests should not require:

- Laravel
- Redis
- Horizon

---

## Infrastructure Tests

Test:

- Redis store saves and restores circuit state.
- Circuit state is shared between processes.
- Redis lock allows only one probe.
- Lock expires safely if a worker crashes.

---

## Application Tests

Test the complete flow:

```text
CircuitBreaker
    ↓
RedisCircuitStore
    ↓
StateMachine
    ↓
Provider
```

---

# 24. Important Production Concerns

## Atomic State Updates

Multiple workers may fail at the same time.

This can cause a race condition:

```text
Worker A reads failures = 4
Worker B reads failures = 4

Worker A increments → 5
Worker B increments → 5
```

The production Redis implementation should use atomic operations or a Lua script where necessary.

The state transition:

```text
CLOSED → OPEN
```

must be safe under concurrent workers.

---

## Lock Ownership

A worker must only release the lock it owns.

The lock implementation should use an ownership token.

Do not implement locks with:

```text
SET key true
```

followed by:

```text
DEL key
```

because another worker may acquire the lock after expiration.

Use Laravel's atomic lock implementation or an equivalent token-based Redis lock.

---

## Failure Window

A more advanced implementation may count failures inside a time window.

Instead of:

```text
5 failures ever
```

use:

```text
5 failures within 60 seconds
```

Otherwise:

```text
10:00 Failure 1
12:00 Failure 2
14:00 Failure 3
18:00 Failure 4
22:00 Failure 5
```

could incorrectly open the circuit despite the provider being mostly healthy.

For high-volume production systems, a sliding or fixed failure window is recommended.

---

## Per-Operation Circuits

Do not necessarily use one circuit for an entire provider.

For example:

```text
twilio-sms:send
twilio-sms:status
twilio-sms:balance
```

One endpoint may be failing while another is healthy.

The circuit name should represent the dependency boundary you actually want to protect.

---

# 25. Final Dependency Rule

The final architecture follows this dependency direction:

```text
┌──────────────────────────┐
│       Application        │
│                          │
│     CircuitBreaker       │
└─────────────┬────────────┘
              │
              ▼
┌──────────────────────────┐
│          Domain          │
│                          │
│ Circuit                   │
│ CircuitState              │
│ CircuitPolicy             │
│ StateMachine              │
│ Contracts                 │
└─────────────▲────────────┘
              │
              │ Implements
              │
┌─────────────┴────────────┐
│      Infrastructure       │
│                           │
│ RedisCircuitStore         │
│ RedisCircuitLock          │
│ HttpFailureClassifier     │
│ SystemClock               │
└───────────────────────────┘
```

The core rule is:

> **The Circuit Breaker domain decides what should happen. Infrastructure decides how state is stored, locks are acquired, and provider failures are detected.**

This separation allows the Circuit Breaker to remain reusable, testable, and independent from Redis or Laravel-specific implementation details.