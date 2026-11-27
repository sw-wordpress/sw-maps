<?php

declare(strict_types=1);

namespace SWMaps\Core\Geocoding;

final class GeocodingException extends \RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const RATE_LIMITED = 'rate_limited';
    public const UNAVAILABLE = 'unavailable';
    public const INVALID_QUERY = 'invalid_query';
    public const NOT_ALLOWED = 'not_allowed';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
