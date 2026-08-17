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

];
