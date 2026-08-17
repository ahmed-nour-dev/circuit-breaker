<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Modules\CircuitBreaker\Infrastructure\Time\SystemClock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SystemClockTest extends TestCase
{
    public function test_system_clock_returns_current_time_in_utc_by_default(): void
    {
        $clock = new SystemClock();
        $now = $clock->now();

        $this->assertInstanceOf(DateTimeImmutable::class, $now);
        $this->assertSame('UTC', $now->getTimezone()->getName());
        $this->assertLessThanOrEqual(time(), $now->getTimestamp());
        $this->assertGreaterThanOrEqual(time() - 2, $now->getTimestamp());
    }

    public function test_system_clock_respects_custom_timezone(): void
    {
        $clock = new SystemClock(new DateTimeZone('Africa/Cairo'));
        $now = $clock->now();

        $this->assertSame('Africa/Cairo', $now->getTimezone()->getName());
    }
}
