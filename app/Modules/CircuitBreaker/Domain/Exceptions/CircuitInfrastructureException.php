<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Domain\Exceptions;

use RuntimeException;
use Throwable;

final class CircuitInfrastructureException extends RuntimeException
{
    public function __construct(
        public readonly string $circuit = '',
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        if ($message === '') {
            $message = $circuit !== ''
                ? sprintf("Circuit '%s' infrastructure failure.", $circuit)
                : 'Circuit infrastructure failure.';
        }

        parent::__construct($message, $code, $previous);
    }
}
