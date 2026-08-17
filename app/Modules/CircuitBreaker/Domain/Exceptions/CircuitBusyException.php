<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Domain\Exceptions;

use RuntimeException;

final class CircuitBusyException extends RuntimeException
{
    public function __construct(
        public readonly string $circuitName,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        if ($message === '') {
            $message = sprintf("Circuit '%s' is HALF-OPEN and another worker is currently probing the dependency.", $circuitName);
        }

        parent::__construct($message, $code, $previous);
    }
}
