<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Domain;

use InvalidArgumentException;

final readonly class CircuitPolicy
{
    /**
     * @param  int  $halfOpenLockTimeout  Probe lock TTL in seconds. It is never extended, so it must outlive the probe call.
     * @param  int|null  $callTimeout  Timeout of the protected call in seconds, when known. Enables validation against the lock TTL.
     */
    public function __construct(
        public int $failureThreshold,
        public int $recoveryTimeout,
        public int $halfOpenLockTimeout = 60,
        public ?int $callTimeout = null,
    ) {
        if ($callTimeout !== null && $halfOpenLockTimeout <= $callTimeout) {
            throw new InvalidArgumentException(sprintf(
                'half_open_lock_timeout (%d s) must be greater than call_timeout (%d s), otherwise the probe lock can expire while the probe is still running.',
                $halfOpenLockTimeout,
                $callTimeout,
            ));
        }
    }
}
