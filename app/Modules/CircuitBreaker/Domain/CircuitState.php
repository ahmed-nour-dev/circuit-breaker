<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Domain;

enum CircuitState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
