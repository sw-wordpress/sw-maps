<?php

declare(strict_types=1);

namespace SWMaps\Core\Exception;

/** Input failed validation. `errors` maps field names to messages (machine readable, English). */
class ValidationException extends \InvalidArgumentException
{
    /** @param array<string, string> $errors */
    public function __construct(private readonly array $errors, string $message = 'Validation failed.')
    {
        parent::__construct($message);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public static function field(string $field, string $message): self
    {
        return new self([$field => $message]);
    }
}
