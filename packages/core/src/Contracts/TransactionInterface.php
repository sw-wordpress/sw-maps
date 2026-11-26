<?php

declare(strict_types=1);

namespace SWMaps\Core\Contracts;

interface TransactionInterface
{
    /**
     * Runs the callback atomically; rolls back and rethrows on any exception.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function run(callable $work): mixed;
}
