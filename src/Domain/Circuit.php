<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Domain;

final class Circuit
{
    public function __construct(
        public readonly string $name,
        public CircuitState $state = CircuitState::Closed,
        public int $failures = 0,
        public ?\DateTimeImmutable $openedUntil = null,
    ) {}

    public function close(): void
    {
        $this->state = CircuitState::Closed;
        $this->failures = 0;
        $this->openedUntil = null;
    }

    public function open(\DateTimeImmutable $until): void
    {
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

    public function recoveryTimePassed(\DateTimeImmutable $now): bool
    {
        return $this->openedUntil === null || $now >= $this->openedUntil;
    }
}
