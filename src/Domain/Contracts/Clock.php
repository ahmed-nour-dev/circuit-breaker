<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Domain\Contracts;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
