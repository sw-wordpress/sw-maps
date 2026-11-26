<?php

declare(strict_types=1);

namespace SWMaps\Core\Import;

/** The file as a whole cannot be imported (unreadable, too large, forbidden construct). Row-level problems are reported, not thrown. */
final class ImportException extends \RuntimeException
{
    public const TOO_LARGE = 'too_large';
    public const TOO_MANY_FEATURES = 'too_many_features';
    public const TOO_MANY_COORDINATES = 'too_many_coordinates';
    public const TOO_DEEP = 'too_deep';
    public const MALFORMED = 'malformed';
    public const FORBIDDEN = 'forbidden';
    public const UNSUPPORTED = 'unsupported';
    public const INVALID_OPTION = 'invalid_option';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
