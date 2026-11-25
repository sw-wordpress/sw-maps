<?php

declare(strict_types=1);

namespace SWMaps\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWMaps\Core\Domain\Geometry;
use SWMaps\Core\Domain\GeometryType;
use SWMaps\Core\Exception\InvalidGeometryException;
use SWMaps\Core\Geo\GeometryValidator;

final class GeometryTest extends TestCase
{
    private const RING = [[0, 0], [4, 0], [4, 4], [0, 0]];

    public function testPoint(): void
    {
        $g = Geometry::point(8.5, 49.0);
        self::assertSame(['type' => 'Point', 'coordinates' => [8.5, 49.0]], $g->toArray());
        self::assertSame([8.5, 49.0, 8.5, 49.0], $g->bounds()->toArray());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function valid(): array
    {
        return [
            'multipoint' => [['type' => 'MultiPoint', 'coordinates' => [[1, 2], [3, 4]]]],
            'line' => [['type' => 'LineString', 'coordinates' => [[1, 2], [3, 4]]]],
            'multiline' => [['type' => 'MultiLineString', 'coordinates' => [[[1, 2], [3, 4]]]]],
            'polygon with hole' => [['type' => 'Polygon', 'coordinates' => [self::RING, [[1, 1], [2, 1], [2, 2], [1, 1]]]]],
            'multipolygon' => [['type' => 'MultiPolygon', 'coordinates' => [[self::RING]]]],
        ];
    }

    /** @param array<string, mixed> $geojson */
    #[DataProvider('valid')]
    public function testValidGeometries(array $geojson): void
    {
        $g = Geometry::fromArray($geojson);
        self::assertSame($geojson['type'], $g->type->value);
        self::assertNotEmpty($g->flatten());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalid(): array
    {
        return [
            'collection' => [['type' => 'GeometryCollection', 'coordinates' => []]],
            'unknown type' => [['type' => 'Circle', 'coordinates' => [1, 2]]],
            'missing coords' => [['type' => 'Point']],
            'line with one point' => [['type' => 'LineString', 'coordinates' => [[1, 2]]]],
            'open ring' => [['type' => 'Polygon', 'coordinates' => [[[0, 0], [4, 0], [4, 4], [1, 1]]]]],
            'short ring' => [['type' => 'Polygon', 'coordinates' => [[[0, 0], [4, 0], [0, 0]]]]],
            'empty polygon' => [['type' => 'Polygon', 'coordinates' => []]],
            'empty multipolygon' => [['type' => 'MultiPolygon', 'coordinates' => []]],
            'out of range' => [['type' => 'Point', 'coordinates' => [200, 0]]],
            'bad nesting' => [['type' => 'LineString', 'coordinates' => [1, 2]]],
            'nan in line' => [['type' => 'LineString', 'coordinates' => [[0, 0], [NAN, 1]]]],
        ];
    }

    /** @param array<string, mixed> $geojson */
    #[DataProvider('invalid')]
    public function testInvalidGeometriesAreRejected(array $geojson): void
    {
        $this->expectException(InvalidGeometryException::class);
        Geometry::fromArray($geojson);
    }

    public function testComplexityLimit(): void
    {
        $this->expectException(InvalidGeometryException::class);
        $this->expectExceptionMessage('limit of 3');
        $positions = [[0, 0], [1, 1], [2, 2], [3, 3]];
        Geometry::create(GeometryType::LineString, $positions, new GeometryValidator(3));
    }

    public function testAltitudeIsRemoved(): void
    {
        $g = Geometry::create(GeometryType::LineString, [[0, 0, 100], [1, 1, 200]]);
        self::assertSame([[0.0, 0.0], [1.0, 1.0]], $g->coordinates);
    }

    public function testTheLimitCountsAllMembersOfMultiGeometries(): void
    {
        $validator = new GeometryValidator(3);
        foreach (
            [
            [GeometryType::MultiLineString, [[[0, 0], [1, 1]], [[2, 2], [3, 3]]]],
            [GeometryType::MultiPolygon, [[self::RING], [self::RING]]],
            [GeometryType::LineString, [[0, 0], [1, 1], [2, 2], [3, 3], [4, 4]]],
            ] as [$type, $coordinates]
        ) {
            try {
                Geometry::create($type, $coordinates, $validator);
                self::fail('accepted an oversized ' . $type->value);
            } catch (InvalidGeometryException $e) {
                self::assertStringContainsString('limit of 3', $e->getMessage());
            }
        }
        self::assertSame(GeometryType::MultiLineString, Geometry::create(GeometryType::MultiLineString, [[[0, 0], [1, 1]]], $validator)->type);
    }
}
