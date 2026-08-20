<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Unit\Domain;

use AhmedNour\CircuitBreaker\Domain\Circuit;
use AhmedNour\CircuitBreaker\Domain\CircuitExecution;
use AhmedNour\CircuitBreaker\Domain\CircuitPolicy;
use AhmedNour\CircuitBreaker\Domain\CircuitState;
use AhmedNour\CircuitBreaker\Domain\StateMachine\CircuitStateHandler;
use AhmedNour\CircuitBreaker\Domain\StateMachine\CircuitStateMachine;
use LogicException;
use PHPUnit\Framework\TestCase;

final class CircuitStateMachineTest extends TestCase
{
    public function test_executes_matching_handler_for_circuit_state(): void
    {
        $closedHandler = $this->createMock(CircuitStateHandler::class);
        $closedHandler->method('supports')->willReturnCallback(fn ($s) => $s === CircuitState::Closed);
        $closedHandler->expects($this->once())->method('execute')->willReturn('closed-result');

        $openHandler = $this->createMock(CircuitStateHandler::class);
        $openHandler->method('supports')->willReturnCallback(fn ($s) => $s === CircuitState::Open);
        $openHandler->expects($this->never())->method('execute');

        $stateMachine = new CircuitStateMachine([$closedHandler, $openHandler]);

        $execution = new CircuitExecution(
            circuit: new Circuit('test-circuit', CircuitState::Closed),
            policy: new CircuitPolicy(3, 60, 10),
            callback: fn () => null,
        );

        $result = $stateMachine->execute($execution);

        $this->assertSame('closed-result', $result);
    }

    public function test_throws_logic_exception_when_no_handler_supports_state(): void
    {
        $stateMachine = new CircuitStateMachine([]);

        $execution = new CircuitExecution(
            circuit: new Circuit('test-circuit', CircuitState::HalfOpen),
            policy: new CircuitPolicy(3, 60, 10),
            callback: fn () => null,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No handler found for state: half_open');

        $stateMachine->execute($execution);
    }
}
