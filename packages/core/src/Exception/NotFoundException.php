<?php

declare(strict_types=1);

namespace SWMaps\Core\Exception;

final class NotFoundException extends \RuntimeException
{
    public static function for(string $entity, int $id): self
    {
        return new self(sprintf('%s %d not found.', $entity, $id));
    }
}
