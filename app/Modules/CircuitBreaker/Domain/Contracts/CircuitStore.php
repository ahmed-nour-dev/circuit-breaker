<?php

declare(strict_types=1);

namespace App\Modules\CircuitBreaker\Domain\Contracts;

use App\Modules\CircuitBreaker\Domain\Circuit;

interface CircuitStore
{
    public function get(string $name): Circuit;

    public function save(Circuit $circuit): void;
}
