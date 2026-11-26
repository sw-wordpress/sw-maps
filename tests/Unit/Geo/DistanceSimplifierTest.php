<?php

declare(strict_types=1);

namespace SWMaps\Tests\Unit\Geo;

use PHPUnit\Framework\TestCase;
use SWMaps\Core\Domain\Geometry;
use SWMaps\Core\Domain\GeometryType;
use SWMaps\Core\Geo\Coordinate;
use SWMaps\Core\Geo\DistanceCalculator;
use SWMaps\Core\Geo\Simplifier;

final class DistanceSimplifierTest extends TestCase
{
    public function testKnownGreatCircleDistances(): void
    {
        $calc = new DistanceCalculator();
        // Berlin - Paris is about 878 km; one degree of latitude about 111.2 km; zero distance for identical points.
        self::assertEqualsWithDelta(878_000.0, $calc->between(new Coordinate(13.405, 52.52), new Coordinate(2.3522, 48.8566)), 3000.0);
        self::assertEqualsWithDelta(111_195.0, $calc->between(new Coordinate(0, 0), new Coordinate(0, 1)), 100.0);
        self::assertSame(0.0, $calc->between(new Coordinate(8, 49), new Coordinate(8, 49)));
    }

    public function testDistanceIsSphericalNotPlanar(): void
    {
        $calc = new DistanceCalculator();
        // One degree of longitude shrinks with latitude: at 60 degrees it is about half of the equatorial value.
        $equator = $calc->between(new Coordinate(0, 0), new Coordinate(1, 0));
        $north = $calc->between(new Coordinate(0, 60), new Coordinate(1, 60));
        self::assertEqualsWithDelta(0.5, $north / $equator, 0.01);
        // Antipodal points: half the circumference, no NaN from rounding.
        self::assertEqualsWithDelta(M_PI * DistanceCalculator::EARTH_RADIUS_M, $calc->between(new Coordinate(0, 0), new Coordinate(180, 0)), 1.0);
    }

    public function testLengthOfLinesOnly(): void
    {
        $calc = new DistanceCalculator();
        $line = Geometry::create(GeometryType::LineString, [[0, 0], [0, 1], [0, 2]]);
        self::assertEqualsWithDelta(222_390.0, $calc->lengthOf($line), 200.0);
        $multi = Geometry::create(GeometryType::MultiLineString, [[[0, 0], [0, 1]], [[10, 0], [10, 1]]]);
        self::assertEqualsWithDelta(222_390.0, $calc->lengthOf($multi), 200.0);
        self::assertSame(0.0, $calc->lengthOf(Geometry::point(1, 1)));
        self::assertSame(0.0, $calc->pathLength([new Coordinate(1, 1)]));
    }

    public function testSimplifyKeepsEndpointsAndShape(): void
    {
        $points = [];
        for ($i = 0; $i <= 100; ++$i) {
            $points[] = [$i * 0.001, 0.0 + ($i % 2) * 1e-7];
        }
        $line = Geometry::create(GeometryType::LineString, $points);
        $simple = (new Simplifier())->simplify($line, 0.0001);
        self::assertSame(2, count($simple->coordinates));
        self::assertSame($points[0], $simple->coordinates[0]);
        self::assertSame($points[100], $simple->coordinates[1]);
        self::assertCount(101, $line->coordinates, 'the canonical geometry is untouched');
        self::assertSame($line, (new Simplifier())->simplify($line, 0.0));
    }

    public function testSimplifyKeepsSignificantVerticesAndValidPolygons(): void
    {
        $corner = Geometry::create(GeometryType::LineString, [[0, 0], [1, 0], [1, 1]]);
        self::assertCount(3, (new Simplifier())->simplify($corner, 0.01)->coordinates);
        $tiny = Geometry::create(GeometryType::Polygon, [[[0, 0], [1e-9, 0], [1e-9, 1e-9], [0, 0]]]);
        $simple = (new Simplifier())->simplify($tiny, 0.5);
        self::assertGreaterThanOrEqual(4, count($simple->coordinates[0]));
        $multiLine = Geometry::create(GeometryType::MultiLineString, [[[0, 0], [0.5, 0.00001], [1, 0]]]);
        self::assertCount(2, (new Simplifier())->simplify($multiLine, 0.01)->coordinates[0]);
        $multiPoly = Geometry::create(GeometryType::MultiPolygon, [[[[0, 0], [4, 0], [4, 4], [0, 4], [0, 0]]]]);
        self::assertCount(5, (new Simplifier())->simplify($multiPoly, 0.01)->coordinates[0][0]);
        $point = Geometry::point(1, 2);
        self::assertSame([1.0, 2.0], (new Simplifier())->simplify($point, 1.0)->coordinates);
        self::assertSame($point->coordinates, (new Simplifier())->simplify($point, 1.0)->coordinates);
        $degenerate = Geometry::create(GeometryType::LineString, [[1, 1], [1, 1], [1, 1]]);
        self::assertCount(2, (new Simplifier())->simplify($degenerate, 0.1)->coordinates);
    }

    public function testToleranceShrinksWithZoom(): void
    {
        self::assertGreaterThan(Simplifier::toleranceForZoom(10), Simplifier::toleranceForZoom(5));
        self::assertEqualsWithDelta(360 / 256, Simplifier::toleranceForZoom(0), 1e-12);
    }
}
