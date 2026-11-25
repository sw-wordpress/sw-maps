<?php

declare(strict_types=1);

namespace SWMaps\Core\Geo;

use SWMaps\Core\Domain\GeometryType;
use SWMaps\Core\Exception\InvalidGeometryException;

/**
 * Validates and normalises GeoJSON coordinates. Rejects non finite or out of range values, malformed nesting,
 * unclosed rings and geometries beyond the configured complexity limits. Invalid polygons are reported, never repaired.
 */
final class GeometryValidator
{
    public function __construct(private readonly int $maxCoordinates = 100000)
    {
    }

    /**
     * @param array<mixed> $coordinates
     * @return array<mixed> normalised coordinates (floats, altitude removed)
     */
    public function validate(GeometryType $type, array $coordinates): array
    {
        $count = 0;
        $result = match ($type) {
            GeometryType::Point => $this->position($coordinates, $count),
            GeometryType::MultiPoint => $this->positions($coordinates, 1, $count),
            GeometryType::LineString => $this->positions($coordinates, 2, $count),
            GeometryType::MultiLineString => $this->each($coordinates, function (mixed $l) use (&$count): array {
                return $this->positions($this->arr($l), 2, $count);
            }),
            GeometryType::Polygon => $this->polygon($coordinates, $count),
            GeometryType::MultiPolygon => $this->each($coordinates, function (mixed $p) use (&$count): array {
                return $this->polygon($this->arr($p), $count);
            }),
        };
        if ($count > $this->maxCoordinates) {
            throw InvalidGeometryException::because(sprintf('Geometry exceeds the limit of %d coordinates.', $this->maxCoordinates));
        }

        return $result;
    }

    /**
     * @param array<mixed> $position
     * @return array{0: float, 1: float}
     */
    private function position(array $position, int &$count): array
    {
        ++$count;

        return Coordinate::fromPosition($position)->toPosition();
    }

    /**
     * @param array<mixed> $list
     * @return list<array{0: float, 1: float}>
     */
    private function positions(array $list, int $min, int &$count): array
    {
        if (!array_is_list($list) || count($list) < $min) {
            throw InvalidGeometryException::because(sprintf('At least %d positions are required.', $min));
        }
        $out = [];
        foreach ($list as $p) {
            $out[] = $this->position($this->arr($p), $count);
            if ($count > $this->maxCoordinates) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $rings
     * @return list<list<array{0: float, 1: float}>>
     */
    private function polygon(array $rings, int &$count): array
    {
        if (!array_is_list($rings) || $rings === []) {
            throw InvalidGeometryException::because('A polygon needs at least one ring.');
        }
        $out = [];
        foreach ($rings as $ring) {
            $positions = $this->positions($this->arr($ring), 4, $count);
            if ($positions[0] !== $positions[count($positions) - 1]) {
                throw InvalidGeometryException::because('Polygon rings must be closed.');
            }
            $out[] = $positions;
        }

        return $out;
    }

    /**
     * @param array<mixed> $list
     * @return list<mixed>
     */
    private function each(array $list, callable $fn): array
    {
        if (!array_is_list($list) || $list === []) {
            throw InvalidGeometryException::because('Multi geometries need at least one member.');
        }

        return array_map($fn, $list);
    }

    /** @return array<mixed> */
    private function arr(mixed $value): array
    {
        if (!is_array($value)) {
            throw InvalidGeometryException::because('Malformed coordinate nesting.');
        }

        return $value;
    }
}
