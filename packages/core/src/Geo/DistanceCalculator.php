<?php

declare(strict_types=1);

namespace SWMaps\Core\Geo;

use SWMaps\Core\Domain\Geometry;
use SWMaps\Core\Domain\GeometryType;

/** Great-circle distances (Haversine) on a sphere with the mean earth radius. Never planar Web Mercator distances. */
final class DistanceCalculator
{
    public const EARTH_RADIUS_M = 6371008.8;

    public function between(Coordinate $a, Coordinate $b): float
    {
        $lat1 = deg2rad($a->latitude);
        $lat2 = deg2rad($b->latitude);
        $dLat = $lat2 - $lat1;
        $dLon = deg2rad($b->longitude - $a->longitude);
        $h = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($h)));
    }

    /** @param list<Coordinate> $coordinates */
    public function pathLength(array $coordinates): float
    {
        $sum = 0.0;
        for ($i = 1, $n = count($coordinates); $i < $n; ++$i) {
            $sum += $this->between($coordinates[$i - 1], $coordinates[$i]);
        }

        return $sum;
    }

    /** Length in metres of LineString / MultiLineString geometries (0 for everything else; polygons have no length here). */
    public function lengthOf(Geometry $geometry): float
    {
        return match ($geometry->type) {
            GeometryType::LineString => $this->pathLength($this->positions($geometry->coordinates)),
            GeometryType::MultiLineString => array_sum(array_map(fn (array $line): float => $this->pathLength($this->positions($line)), $geometry->coordinates)),
            default => 0.0,
        };
    }

    /**
     * @param array<mixed> $list
     * @return list<Coordinate>
     */
    private function positions(array $list): array
    {
        return array_map(static fn (mixed $p): Coordinate => Coordinate::fromPosition($p), array_values($list));
    }
}
