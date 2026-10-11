<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Domain\Contracts;

use AhmedNour\CircuitBreaker\Domain\Circuit;
use DateTimeImmutable;

interface CircuitStore
{
    public function get(string $name): Circuit;

    public function save(Circuit $circuit): void;

    /**
     * Atomically count a tripping failure. Only a closed circuit changes: it is opened
     * once the threshold is reached. A failure that lands on an open or half-open
     * circuit (a call that started before it opened) is ignored.
     *
     * @return Circuit the circuit as stored after the call
     */
    public function recordFailure(string $name, int $threshold, DateTimeImmutable $openUntil): Circuit;

    /**
     * Atomically reset the failure counter, but only while the circuit is closed.
     * A success from a call that started before the circuit opened must not close it.
     */
    public function recordSuccess(string $name): void;

    /**
     * Atomically move open -> half-open, only if still open and the recovery time has passed.
     *
     * @return bool true if this call performed the transition
     */
    public function transitionToHalfOpen(string $name, DateTimeImmutable $now): bool;

    /**
     * Atomically move half-open -> closed, only if still half-open.
     */
    public function closeFromHalfOpen(string $name): bool;

    /**
     * Atomically move half-open -> open, only if still half-open.
     */
    public function reopenFromHalfOpen(string $name, DateTimeImmutable $until): bool;
}
