<?php

declare(strict_types=1);

namespace SWMaps\Core\Geo;

use SWMaps\Core\Exception\InvalidGeometryException;

/** WGS84 position. GeoJSON order is [longitude, latitude]. Latitude is limited to the Web Mercator range. */
final class Coordinate
{
    public const MAX_LONGITUDE = 180.0;
    public const MAX_LATITUDE = 85.0511287798066;

    public function __construct(public readonly float $longitude, public readonly float $latitude)
    {
        if (!is_finite($longitude) || !is_finite($latitude)) {
            throw InvalidGeometryException::because('Coordinates must be finite numbers.');
        }
        if (abs($longitude) > self::MAX_LONGITUDE) {
            throw InvalidGeometryException::because('Longitude must be between -180 and 180.');
        }
        if (abs($latitude) > self::MAX_LATITUDE) {
            throw InvalidGeometryException::because('Latitude must be between -85.0511 and 85.0511.');
        }
    }

    /** Accepts a GeoJSON position ([lon, lat] or [lon, lat, altitude]); the altitude is discarded. */
    public static function fromPosition(mixed $position): self
    {
        if (!is_array($position) || !array_is_list($position) || count($position) < 2 || count($position) > 3) {
            throw InvalidGeometryException::because('A position must be [longitude, latitude].');
        }
        foreach ($position as $value) {
            if (!is_int($value) && !is_float($value)) {
                throw InvalidGeometryException::because('Coordinates must be numbers.');
            }
        }

        return new self((float) $position[0], (float) $position[1]);
    }

    /** @return array{0: float, 1: float} */
    public function toPosition(): array
    {
        return [$this->longitude, $this->latitude];
    }
}
