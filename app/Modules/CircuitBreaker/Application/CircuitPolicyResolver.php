<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Application;

use App\Modules\CircuitBreaker\Domain\CircuitPolicy;

final class CircuitPolicyResolver
{
    /**
     * @param array<string, array{failure_threshold?: int, recovery_timeout?: int, half_open_lock_timeout?: int}> $providers
     * @param CircuitPolicy|null $defaultPolicy
     */
    public function __construct(
        private readonly array $providers = [],
        private readonly ?CircuitPolicy $defaultPolicy = null,
    ) {
    }

    public function resolve(string $name): CircuitPolicy
    {
        $config = $this->providers[$name] ?? null;

        if ($config !== null) {
            $default = $this->getDefaultPolicy();

            return new CircuitPolicy(
                failureThreshold: (int) ($config['failure_threshold'] ?? $default->failureThreshold),
                recoveryTimeout: (int) ($config['recovery_timeout'] ?? $default->recoveryTimeout),
                halfOpenLockTimeout: (int) ($config['half_open_lock_timeout'] ?? $default->halfOpenLockTimeout),
            );
        }

        return $this->getDefaultPolicy();
    }

    public function getDefaultPolicy(): CircuitPolicy
    {
        return $this->defaultPolicy ?? new CircuitPolicy(
            failureThreshold: 5,
            recoveryTimeout: 60,
            halfOpenLockTimeout: 10,
        );
    }
}
