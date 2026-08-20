<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Domain;

final readonly class CircuitPolicy
{
    public function __construct(
        public int $failureThreshold,
        public int $recoveryTimeout,
        public int $halfOpenLockTimeout = 10,
    ) {}
}
