<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Failure;

use App\Modules\CircuitBreaker\Domain\Circuit;
use App\Modules\CircuitBreaker\Domain\CircuitExecution;
use App\Modules\CircuitBreaker\Domain\CircuitPolicy;
use App\Modules\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
use App\Modules\CircuitBreaker\Infrastructure\Failure\FailOpenHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FailOpenHandlerTest extends TestCase
{
    public function test_executes_callback_and_returns_result(): void
    {
        $handler = new FailOpenHandler();

        $execution = new CircuitExecution(
            circuit: new Circuit('twilio-sms'),
            policy: new CircuitPolicy(5, 60, 10),
            callback: fn () => 'sms-sent',
        );

        $result = $handler->handle(
            new CircuitInfrastructureException('twilio-sms'),
            $execution,
        );

        $this->assertSame('sms-sent', $result);
    }

    public function test_propagates_callback_exception_if_callback_fails(): void
    {
        $handler = new FailOpenHandler();

        $execution = new CircuitExecution(
            circuit: new Circuit('twilio-sms'),
            policy: new CircuitPolicy(5, 60, 10),
            callback: fn () => throw new RuntimeException('Twilio API is down'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Twilio API is down');

        $handler->handle(
            new CircuitInfrastructureException('twilio-sms'),
            $execution,
        );
    }
}
