<?php

declare(strict_types=1);

namespace SWMaps\Core\Geo;

use SWMaps\Core\Domain\Geometry;
use SWMaps\Core\Domain\GeometryType;

/**
 * Douglas-Peucker simplification for display variants of large tracks. The canonical geometry is never changed:
 * the result is a new, valid Geometry used for rendering only. Tolerance is in degrees (applied to lon/lat).
 */
final class Simplifier
{
    public function simplify(Geometry $geometry, float $tolerance): Geometry
    {
        if ($tolerance <= 0.0) {
            return $geometry;
        }
        $coordinates = match ($geometry->type) {
            GeometryType::LineString => $this->line($geometry->coordinates, $tolerance, 2),
            GeometryType::MultiLineString => array_map(fn (array $l): array => $this->line($l, $tolerance, 2), $geometry->coordinates),
            GeometryType::Polygon => array_map(fn (array $r): array => $this->line($r, $tolerance, 4), $geometry->coordinates),
            GeometryType::MultiPolygon => array_map(fn (array $p): array => array_map(fn (array $r): array => $this->line($r, $tolerance, 4), $p), $geometry->coordinates),
            default => $geometry->coordinates,
        };

        return Geometry::create($geometry->type, $coordinates);
    }

    /** Tolerance for a zoom level: about one screen pixel (256 px tiles) at that zoom. */
    public static function toleranceForZoom(float $zoom): float
    {
        return 360.0 / (256.0 * (2 ** $zoom));
    }

    /**
     * @param array<mixed> $points
     * @return list<array{0: float, 1: float}>
     */
    private function line(array $points, float $tolerance, int $minPoints): array
    {
        $points = array_values($points);
        $n = count($points);
        if ($n <= $minPoints) {
            return $points;
        }
        $keep = array_fill(0, $n, false);
        $keep[0] = $keep[$n - 1] = true;
        $stack = [[0, $n - 1]];
        while ($stack !== []) {
            [$first, $last] = array_pop($stack);
            $maxDistance = 0.0;
            $index = -1;
            for ($i = $first + 1; $i < $last; ++$i) {
                $d = $this->distanceToSegment($points[$i], $points[$first], $points[$last]);
                if ($d > $maxDistance) {
                    $maxDistance = $d;
                    $index = $i;
                }
            }
            if ($index !== -1 && $maxDistance > $tolerance) {
                $keep[$index] = true;
                $stack[] = [$first, $index];
                $stack[] = [$index, $last];
            }
        }
        $out = [];
        foreach ($points as $i => $p) {
            if ($keep[$i]) {
                $out[] = $p;
            }
        }
        // A ring must keep at least four positions to stay a valid polygon: fall back to the original then.
        return count($out) < $minPoints ? $points : $out;
    }

    /**
     * @param array{0: float, 1: float} $p
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     */
    private function distanceToSegment(array $p, array $a, array $b): float
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $len2 = $dx * $dx + $dy * $dy;
        if ($len2 === 0.0) {
            return hypot($p[0] - $a[0], $p[1] - $a[1]);
        }
        $t = max(0.0, min(1.0, (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / $len2));

        return hypot($p[0] - ($a[0] + $t * $dx), $p[1] - ($a[1] + $t * $dy));
    }
}
