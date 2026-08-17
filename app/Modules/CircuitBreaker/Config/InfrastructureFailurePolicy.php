<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Config;

enum InfrastructureFailurePolicy: string
{
    case FailOpen = 'fail_open';
    case FailClosed = 'fail_closed';
    case Fallback = 'fallback';
}
