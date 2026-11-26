<?php

declare(strict_types=1);

namespace SWMaps\Core\Exception;

/** A geometry or coordinate is invalid (non finite, out of range, malformed, too complex). */
final class InvalidGeometryException extends ValidationException
{
    public static function because(string $message, string $field = 'geometry'): self
    {
        return new self([$field => $message], $message);
    }
}
