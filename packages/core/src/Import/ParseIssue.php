<?php

declare(strict_types=1);

namespace SWMaps\Core\Import;

final class ParseIssue
{
    public function __construct(public readonly int $index, public readonly string $message)
    {
    }

    /** @return array{index: int, message: string} */
    public function toArray(): array
    {
        return ['index' => $this->index, 'message' => $this->message];
    }
}
