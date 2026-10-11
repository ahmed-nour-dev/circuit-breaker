<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Application;

use AhmedNour\CircuitBreaker\Domain\CircuitPolicy;

final class CircuitPolicyResolver
{
    /**
     * @param  array<string, array{failure_threshold?: int, recovery_timeout?: int, half_open_lock_timeout?: int, call_timeout?: int}>  $providers
     */
    public function __construct(
        private readonly array $providers = [],
        private readonly ?CircuitPolicy $defaultPolicy = null,
    ) {}

    public function resolve(string $name): CircuitPolicy
    {
        $config = $this->providers[$name] ?? null;

        if ($config !== null) {
            $default = $this->getDefaultPolicy();

            return new CircuitPolicy(
                failureThreshold: (int) ($config['failure_threshold'] ?? $default->failureThreshold),
                recoveryTimeout: (int) ($config['recovery_timeout'] ?? $default->recoveryTimeout),
                halfOpenLockTimeout: (int) ($config['half_open_lock_timeout'] ?? $default->halfOpenLockTimeout),
                callTimeout: isset($config['call_timeout']) ? (int) $config['call_timeout'] : $default->callTimeout,
            );
        }

        return $this->getDefaultPolicy();
    }

    public function getDefaultPolicy(): CircuitPolicy
    {
        return $this->defaultPolicy ?? new CircuitPolicy(
            failureThreshold: 5,
            recoveryTimeout: 60,
            halfOpenLockTimeout: 60,
        );
    }
}
