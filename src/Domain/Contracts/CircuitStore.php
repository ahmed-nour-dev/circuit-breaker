<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Domain\Contracts;

use AhmedNour\CircuitBreaker\Domain\Circuit;

interface CircuitStore
{
    public function get(string $name): Circuit;

    public function save(Circuit $circuit): void;
}
