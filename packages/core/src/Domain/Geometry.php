<?php

declare(strict_types=1);

namespace SWMaps\Core\Domain;

use SWMaps\Core\Exception\InvalidGeometryException;
use SWMaps\Core\Geo\Bounds;
use SWMaps\Core\Geo\Coordinate;
use SWMaps\Core\Geo\GeometryValidator;

/** Canonical GeoJSON geometry (RFC 7946, WGS84, [lon, lat]). Instances are always valid. */
final class Geometry
{
    /** @param array<mixed> $coordinates validated nested GeoJSON coordinates */
    private function __construct(public readonly GeometryType $type, public readonly array $coordinates)
    {
    }

    /** @param array<mixed> $coordinates */
    public static function create(GeometryType $type, array $coordinates, ?GeometryValidator $validator = null): self
    {
        $normalised = ($validator ?? new GeometryValidator())->validate($type, $coordinates);

        return new self($type, $normalised);
    }

    public static function point(float $longitude, float $latitude): self
    {
        return self::create(GeometryType::Point, [$longitude, $latitude]);
    }

    /** @param array<mixed> $geojson decoded GeoJSON geometry object */
    public static function fromArray(array $geojson, ?GeometryValidator $validator = null): self
    {
        $type = $geojson['type'] ?? null;
        if ($type === 'GeometryCollection') {
            throw InvalidGeometryException::because('GeometryCollection is not supported.');
        }
        $enum = is_string($type) ? GeometryType::tryFrom($type) : null;
        if ($enum === null) {
            throw InvalidGeometryException::because('Unknown geometry type.');
        }
        $coordinates = $geojson['coordinates'] ?? null;
        if (!is_array($coordinates)) {
            throw InvalidGeometryException::because('Geometry coordinates are missing.');
        }

        return self::create($enum, $coordinates, $validator);
    }

    /** @return array{type: string, coordinates: array<mixed>} */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'coordinates' => $this->coordinates];
    }

    /** @return list<Coordinate> */
    public function flatten(): array
    {
        $out = [];
        $walk = static function (array $node) use (&$walk, &$out): void {
            if (isset($node[0]) && !is_array($node[0])) {
                $out[] = Coordinate::fromPosition($node);

                return;
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($this->coordinates);

        return $out;
    }

    public function bounds(): Bounds
    {
        return Bounds::ofCoordinates($this->flatten());
    }
}
