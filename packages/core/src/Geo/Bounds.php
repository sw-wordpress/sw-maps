<?php

declare(strict_types=1);

namespace SWMaps\Core\Geo;

use SWMaps\Core\Exception\InvalidGeometryException;

/** Axis aligned bounding box (no antimeridian wrapping). */
final class Bounds
{
    public function __construct(
        public readonly float $minLongitude,
        public readonly float $minLatitude,
        public readonly float $maxLongitude,
        public readonly float $maxLatitude
    ) {
        new Coordinate($minLongitude, $minLatitude);
        new Coordinate($maxLongitude, $maxLatitude);
        if ($minLongitude > $maxLongitude || $minLatitude > $maxLatitude) {
            throw InvalidGeometryException::because('Bounds minimum must not exceed maximum.', 'bounds');
        }
    }

    /** @param list<Coordinate> $coordinates */
    public static function ofCoordinates(array $coordinates): self
    {
        if ($coordinates === []) {
            throw InvalidGeometryException::because('Bounds need at least one coordinate.', 'bounds');
        }
        $minLon = $maxLon = $coordinates[0]->longitude;
        $minLat = $maxLat = $coordinates[0]->latitude;
        foreach ($coordinates as $c) {
            $minLon = min($minLon, $c->longitude);
            $maxLon = max($maxLon, $c->longitude);
            $minLat = min($minLat, $c->latitude);
            $maxLat = max($maxLat, $c->latitude);
        }

        return new self($minLon, $minLat, $maxLon, $maxLat);
    }

    /** @param mixed $value [minLon, minLat, maxLon, maxLat] */
    public static function fromArray(mixed $value): self
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== 4) {
            throw InvalidGeometryException::because('Bounds must be [minLon, minLat, maxLon, maxLat].', 'bounds');
        }
        foreach ($value as $v) {
            if (!is_int($v) && !is_float($v)) {
                throw InvalidGeometryException::because('Bounds values must be numbers.', 'bounds');
            }
        }

        return new self((float) $value[0], (float) $value[1], (float) $value[2], (float) $value[3]);
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    public function toArray(): array
    {
        return [$this->minLongitude, $this->minLatitude, $this->maxLongitude, $this->maxLatitude];
    }

    public function center(): Coordinate
    {
        return new Coordinate(($this->minLongitude + $this->maxLongitude) / 2, ($this->minLatitude + $this->maxLatitude) / 2);
    }

    public function intersects(self $other): bool
    {
        return $this->minLongitude <= $other->maxLongitude && $this->maxLongitude >= $other->minLongitude
            && $this->minLatitude <= $other->maxLatitude && $this->maxLatitude >= $other->minLatitude;
    }

    public function merge(self $other): self
    {
        return new self(
            min($this->minLongitude, $other->minLongitude),
            min($this->minLatitude, $other->minLatitude),
            max($this->maxLongitude, $other->maxLongitude),
            max($this->maxLatitude, $other->maxLatitude)
        );
    }
}
