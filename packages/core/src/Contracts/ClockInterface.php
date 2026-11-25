<?php

declare(strict_types=1);

namespace SWMaps\Core\Contracts;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
