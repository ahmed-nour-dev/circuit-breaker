<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Support;

use AhmedNour\CircuitBreaker\Domain\Contracts\Clock;
use DateTimeImmutable;
use DateTimeZone;

final class FakeClock implements Clock
{
    private DateTimeImmutable $currentTime;

    public function __construct(?DateTimeImmutable $initialTime = null)
    {
        $this->currentTime = $initialTime ?? new DateTimeImmutable('2026-08-17 12:00:00', new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->currentTime;
    }

    public function setTime(DateTimeImmutable $time): void
    {
        $this->currentTime = $time;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->currentTime = $this->currentTime->modify(sprintf('+%d seconds', $seconds));
    }
}
