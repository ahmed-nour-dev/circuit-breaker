<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Unit\Domain;

use AhmedNour\CircuitBreaker\Domain\Circuit;
use AhmedNour\CircuitBreaker\Domain\CircuitState;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class CircuitTest extends TestCase
{
    public function test_new_circuit_initializes_with_default_closed_state(): void
    {
        $circuit = new Circuit('sms-service');

        $this->assertSame('sms-service', $circuit->name);
        $this->assertSame(CircuitState::Closed, $circuit->state);
        $this->assertSame(0, $circuit->failures);
        $this->assertNull($circuit->openedUntil);
    }

    public function test_close_resets_state_failures_and_opened_until(): void
    {
        $circuit = new Circuit(
            name: 'payment-service',
            state: CircuitState::Open,
            failures: 5,
            openedUntil: new DateTimeImmutable('2026-08-17 15:00:00', new DateTimeZone('UTC')),
        );

        $circuit->close();

        $this->assertSame(CircuitState::Closed, $circuit->state);
        $this->assertSame(0, $circuit->failures);
        $this->assertNull($circuit->openedUntil);
    }

    public function test_open_transitions_state_and_records_opened_until(): void
    {
        $circuit = new Circuit('email-service');
        $until = new DateTimeImmutable('2026-08-17 16:00:00', new DateTimeZone('UTC'));

        $circuit->open($until);

        $this->assertSame(CircuitState::Open, $circuit->state);
        $this->assertSame($until, $circuit->openedUntil);
    }

    public function test_half_open_transitions_state_to_half_open(): void
    {
        $circuit = new Circuit('crm-service', CircuitState::Open);

        $circuit->halfOpen();

        $this->assertSame(CircuitState::HalfOpen, $circuit->state);
    }

    public function test_record_failure_increments_failure_counter(): void
    {
        $circuit = new Circuit('sms-service');

        $this->assertSame(0, $circuit->failures);

        $circuit->recordFailure();
        $this->assertSame(1, $circuit->failures);

        $circuit->recordFailure();
        $this->assertSame(2, $circuit->failures);
    }

    public function test_recovery_time_passed_returns_true_when_opened_until_is_null(): void
    {
        $circuit = new Circuit('sms-service');
        $now = new DateTimeImmutable('2026-08-17 12:00:00', new DateTimeZone('UTC'));

        $this->assertTrue($circuit->recoveryTimePassed($now));
    }

    public function test_recovery_time_passed_returns_false_when_now_is_before_opened_until(): void
    {
        $circuit = new Circuit('sms-service');
        $until = new DateTimeImmutable('2026-08-17 12:30:00', new DateTimeZone('UTC'));
        $circuit->open($until);

        $now = new DateTimeImmutable('2026-08-17 12:15:00', new DateTimeZone('UTC'));

        $this->assertFalse($circuit->recoveryTimePassed($now));
    }

    public function test_recovery_time_passed_returns_true_when_now_is_equal_or_after_opened_until(): void
    {
        $circuit = new Circuit('sms-service');
        $until = new DateTimeImmutable('2026-08-17 12:30:00', new DateTimeZone('UTC'));
        $circuit->open($until);

        $exactNow = new DateTimeImmutable('2026-08-17 12:30:00', new DateTimeZone('UTC'));
        $afterNow = new DateTimeImmutable('2026-08-17 12:35:00', new DateTimeZone('UTC'));

        $this->assertTrue($circuit->recoveryTimePassed($exactNow));
        $this->assertTrue($circuit->recoveryTimePassed($afterNow));
    }
}
