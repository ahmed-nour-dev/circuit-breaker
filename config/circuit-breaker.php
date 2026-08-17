<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Circuit Breaker Policy
    |--------------------------------------------------------------------------
    |
    | Default values applied when a provider does not specify an explicit policy.
    |
    */
    'default' => [
        'failure_threshold' => (int) (getenv('CIRCUIT_BREAKER_DEFAULT_THRESHOLD') ?: 5),
        'recovery_timeout' => (int) (getenv('CIRCUIT_BREAKER_DEFAULT_RECOVERY_TIMEOUT') ?: 60),
        'half_open_lock_timeout' => (int) (getenv('CIRCUIT_BREAKER_DEFAULT_LOCK_TIMEOUT') ?: 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Specific Policies
    |--------------------------------------------------------------------------
    |
    | Define custom failure thresholds, recovery timeouts, and probe lock
    | timeouts per provider or dependency.
    |
    */
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

    /*
    |--------------------------------------------------------------------------
    | Redis Connection & Key Prefixes
    |--------------------------------------------------------------------------
    */
    'redis' => [
        'connection' => (string) (getenv('CIRCUIT_BREAKER_REDIS_CONNECTION') ?: 'default'),
        'prefix' => 'circuit:',
        'lock_suffix' => ':probe-lock',
    ],

    /*
    |--------------------------------------------------------------------------
    | Infrastructure Failure Handling
    |--------------------------------------------------------------------------
    |
    | Defines the behavior when circuit breaker infrastructure (e.g. Redis,
    | state store, or lock provider) is unavailable.
    |
    | Supported strategies:
    | - 'fail_open'   : Execute protected operation without circuit protection (default)
    | - 'fail_closed' : Reject operation with CircuitInfrastructureException
    | - 'fallback'    : Delegate to a configured InfrastructureFallback implementation
    |
    */
    'infrastructure_failure' => [
        'strategy' => (string) (getenv('CIRCUIT_BREAKER_INFRASTRUCTURE_FAILURE_STRATEGY') ?: 'fail_open'),
        'fallback' => null,
    ],

];

