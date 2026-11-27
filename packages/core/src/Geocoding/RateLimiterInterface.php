<?php

declare(strict_types=1);

namespace SWMaps\Core\Geocoding;

interface RateLimiterInterface
{
    /** Takes the slot for `$key` when at least `$intervalSeconds` passed since the last one; false when it is too early. */
    public function acquire(string $key, int $intervalSeconds): bool;
}
