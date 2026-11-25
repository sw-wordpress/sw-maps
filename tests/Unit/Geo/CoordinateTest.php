<?php

declare(strict_types=1);

namespace SWMaps\Tests\Unit\Geo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWMaps\Core\Exception\InvalidGeometryException;
use SWMaps\Core\Geo\Bounds;
use SWMaps\Core\Geo\Coordinate;

final class CoordinateTest extends TestCase
{
    public function testValidCoordinateKeepsGeoJsonOrder(): void
    {
        $c = new Coordinate(10.5, 50.25);
        self::assertSame([10.5, 50.25], $c->toPosition());
    }

    /** @return array<string, array{float, float}> */
    public static function invalid(): array
    {
        return [
            'lon too big' => [180.1, 0.0],
            'lon too small' => [-181.0, 0.0],
            'lat beyond mercator' => [0.0, 85.06],
            'lat negative beyond' => [0.0, -90.0],
            'nan' => [NAN, 0.0],
            'inf' => [0.0, INF],
        ];
    }

    #[DataProvider('invalid')]
    public function testInvalidCoordinatesAreRejected(float $lon, float $lat): void
    {
        $this->expectException(InvalidGeometryException::class);
        $make = static fn (): Coordinate => new Coordinate($lon, $lat);
        $make();
    }

    public function testBoundaryValuesAreAccepted(): void
    {
        self::assertSame(180.0, (new Coordinate(180, 85.0511287798066))->longitude);
    }

    public function testFromPositionDropsAltitude(): void
    {
        self::assertSame([1.0, 2.0], Coordinate::fromPosition([1, 2, 300])->toPosition());
    }

    /** @return array<string, array{mixed}> */
    public static function badPositions(): array
    {
        return ['string' => ['x'], 'short' => [[1]], 'long' => [[1, 2, 3, 4]], 'strings' => [['1', '2']], 'assoc' => [['lon' => 1, 'lat' => 2]]];
    }

    #[DataProvider('badPositions')]
    public function testFromPositionRejectsMalformedInput(mixed $position): void
    {
        $this->expectException(InvalidGeometryException::class);
        Coordinate::fromPosition($position);
    }

    public function testBounds(): void
    {
        $b = Bounds::ofCoordinates([new Coordinate(1, 2), new Coordinate(5, -3)]);
        self::assertSame([1.0, -3.0, 5.0, 2.0], $b->toArray());
        self::assertSame([3.0, -0.5], $b->center()->toPosition());
        self::assertTrue($b->intersects(new Bounds(4, 0, 9, 1)));
        self::assertFalse($b->intersects(new Bounds(6, 0, 9, 1)));
        self::assertSame([1.0, -3.0, 9.0, 2.0], $b->merge(new Bounds(6, 0, 9, 1))->toArray());
        self::assertSame($b->toArray(), Bounds::fromArray([1, -3, 5, 2])->toArray());
    }

    public function testBoundsRejectInvalid(): void
    {
        $this->expectException(InvalidGeometryException::class);
        $make = static fn (): Bounds => new Bounds(5, 0, 1, 1);
        $make();
    }

    public function testBoundsRejectEmptyAndMalformed(): void
    {
        $this->expectException(InvalidGeometryException::class);
        Bounds::ofCoordinates([]);
    }

    public function testBoundsFromMalformedArray(): void
    {
        $this->expectException(InvalidGeometryException::class);
        Bounds::fromArray([1, 2, 3]);
    }

    public function testBoundsFromNonNumeric(): void
    {
        $this->expectException(InvalidGeometryException::class);
        Bounds::fromArray(['a', 2, 3, 4]);
    }
}
