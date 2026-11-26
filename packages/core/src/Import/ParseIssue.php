<?php

declare(strict_types=1);

namespace SWMaps\Core\Import;

/**
 * A problem of one source entry. `code` is stable and translated by the client; `message` is an English fallback
 * (and carries technical detail such as the geometry error).
 */
final class ParseIssue
{
    /** @param array<string, string> $params values for the translated text */
    public function __construct(
        public readonly int $index,
        public readonly string $code,
        public readonly string $message,
        public readonly array $params = []
    ) {
    }

    /** @return array{index: int, code: string, message: string, params: array<string, string>} */
    public function toArray(): array
    {
        return ['index' => $this->index, 'code' => $this->code, 'message' => $this->message, 'params' => $this->params];
    }
}
