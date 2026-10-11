<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Support;

use AhmedNour\CircuitBreaker\Domain\Circuit;
use DateTimeImmutable;
use LogicException;

/**
 * For anonymous CircuitStore test doubles that fail in get()/save() and never reach a transition.
 */
trait UnusedStoreTransitions
{
    public function recordFailure(string $name, int $threshold, DateTimeImmutable $openUntil): Circuit
    {
        throw new LogicException('Unexpected call.');
    }

    public function recordSuccess(string $name): void
    {
        throw new LogicException('Unexpected call.');
    }

    public function transitionToHalfOpen(string $name, DateTimeImmutable $now): bool
    {
        throw new LogicException('Unexpected call.');
    }

    public function closeFromHalfOpen(string $name): bool
    {
        throw new LogicException('Unexpected call.');
    }

    public function reopenFromHalfOpen(string $name, DateTimeImmutable $until): bool
    {
        throw new LogicException('Unexpected call.');
    }
}
