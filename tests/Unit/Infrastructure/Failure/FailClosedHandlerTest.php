<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Failure;

use App\Modules\CircuitBreaker\Domain\Circuit;
use App\Modules\CircuitBreaker\Domain\CircuitExecution;
use App\Modules\CircuitBreaker\Domain\CircuitPolicy;
use App\Modules\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
use App\Modules\CircuitBreaker\Infrastructure\Failure\FailClosedHandler;
use Exception;
use PHPUnit\Framework\TestCase;

final class FailClosedHandlerTest extends TestCase
{
    public function test_rethrows_circuit_infrastructure_exception(): void
    {
        $handler = new FailClosedHandler();

        $originalException = new CircuitInfrastructureException('payment-gateway');
        $execution = new CircuitExecution(
            circuit: new Circuit('payment-gateway'),
            policy: new CircuitPolicy(3, 60, 10),
            callback: fn () => 'should-not-run',
        );

        $this->expectException(CircuitInfrastructureException::class);
        $this->expectExceptionMessage("Circuit 'payment-gateway' infrastructure failure.");

        $handler->handle($originalException, $execution);
    }

    public function test_wraps_other_exception_in_circuit_infrastructure_exception(): void
    {
        $handler = new FailClosedHandler();

        $nativeException = new Exception('Redis socket write error');
        $execution = new CircuitExecution(
            circuit: new Circuit('payment-gateway'),
            policy: new CircuitPolicy(3, 60, 10),
            callback: fn () => 'should-not-run',
        );

        try {
            $handler->handle($nativeException, $execution);
            $this->fail('Expected CircuitInfrastructureException');
        } catch (CircuitInfrastructureException $e) {
            $this->assertSame('payment-gateway', $e->circuit);
            $this->assertSame($nativeException, $e->getPrevious());
        }
    }
}
