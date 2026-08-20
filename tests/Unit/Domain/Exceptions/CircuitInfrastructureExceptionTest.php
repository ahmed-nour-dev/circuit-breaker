<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Unit\Domain\Exceptions;

use AhmedNour\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
use Exception;
use PHPUnit\Framework\TestCase;

final class CircuitInfrastructureExceptionTest extends TestCase
{
    public function test_default_message_with_circuit_name(): void
    {
        $previous = new Exception('Redis connection lost');
        $exception = new CircuitInfrastructureException(
            circuit: 'twilio-sms',
            previous: $previous,
        );

        $this->assertSame('twilio-sms', $exception->circuit);
        $this->assertSame("Circuit 'twilio-sms' infrastructure failure.", $exception->getMessage());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame(0, $exception->getCode());
    }

    public function test_custom_message(): void
    {
        $exception = new CircuitInfrastructureException(
            circuit: 'payment-gateway',
            message: 'Custom storage timeout',
        );

        $this->assertSame('payment-gateway', $exception->circuit);
        $this->assertSame('Custom storage timeout', $exception->getMessage());
        $this->assertNull($exception->getPrevious());
    }

    public function test_default_message_without_circuit_name(): void
    {
        $exception = new CircuitInfrastructureException;

        $this->assertSame('', $exception->circuit);
        $this->assertSame('Circuit infrastructure failure.', $exception->getMessage());
    }
}
