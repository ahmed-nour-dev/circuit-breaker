<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Infrastructure\Time;

use AhmedNour\CircuitBreaker\Domain\Contracts\Clock;
use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements Clock
{
    public function __construct(
        private readonly ?DateTimeZone $timezone = null,
    ) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone ?? new DateTimeZone('UTC'));
    }
}
