<?php

declare(strict_types=1);

namespace SWMaps\Tests\Support;

use SWMaps\Core\Contracts\TransactionInterface;

final class InMemoryTransaction implements TransactionInterface
{
    public int $runs = 0;

    public function run(callable $work): mixed
    {
        ++$this->runs;

        return $work();
    }
}
