<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Domain\Exceptions;

use RuntimeException;

final class CircuitOpenException extends RuntimeException
{
    public function __construct(
        public readonly string $circuitName,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        if ($message === '') {
            $message = sprintf("Circuit '%s' is OPEN and failing fast.", $circuitName);
        }

        parent::__construct($message, $code, $previous);
    }
}
