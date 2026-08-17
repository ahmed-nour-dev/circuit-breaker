<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Failure;

use App\Modules\CircuitBreaker\Domain\Circuit;
use App\Modules\CircuitBreaker\Domain\CircuitExecution;
use App\Modules\CircuitBreaker\Domain\CircuitPolicy;
use App\Modules\CircuitBreaker\Domain\Contracts\InfrastructureFallback;
use App\Modules\CircuitBreaker\Domain\Exceptions\CircuitInfrastructureException;
use App\Modules\CircuitBreaker\Infrastructure\Failure\FallbackHandler;
use PHPUnit\Framework\TestCase;
use Throwable;

final class FallbackHandlerTest extends TestCase
{
    public function test_delegates_to_infrastructure_fallback(): void
    {
        $fallback = new class implements InfrastructureFallback {
            public bool $invoked = false;
            public ?CircuitExecution $receivedExecution = null;
            public ?Throwable $receivedException = null;

            public function execute(CircuitExecution $execution, Throwable $exception): mixed
            {
                $this->invoked = true;
                $this->receivedExecution = $execution;
                $this->receivedException = $exception;

                return 'fallback-response';
            }
        };

        $handler = new FallbackHandler($fallback);
        $exception = new CircuitInfrastructureException('crm-api');
        $execution = new CircuitExecution(
            circuit: new Circuit('crm-api'),
            policy: new CircuitPolicy(5, 60, 10),
            callback: fn () => 'primary-response',
        );

        $result = $handler->handle($exception, $execution);

        $this->assertSame('fallback-response', $result);
        $this->assertTrue($fallback->invoked);
        $this->assertSame($execution, $fallback->receivedExecution);
        $this->assertSame($exception, $fallback->receivedException);
    }
}
