<?php

declare(strict_types=1);

namespace SWMaps\Tests\Unit\Import;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWMaps\Core\Import\CsvImporter;
use SWMaps\Core\Import\GeoJsonImporter;
use SWMaps\Core\Import\GpxImporter;
use SWMaps\Core\Import\ImportException;
use SWMaps\Core\Import\ImportLimits;

final class ImportersTest extends TestCase
{
    private const GPX = '<?xml version="1.0"?><gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
        . '<wpt lat="49.0" lon="8.4"><name>Start</name><desc>Hut</desc><link href="https://example.org/a"/></wpt>'
        . '<rte><name>Route</name><rtept lat="49" lon="8"/><rtept lat="49.1" lon="8.1"/></rte>'
        . '<trk><name>Track</name><trkseg><trkpt lat="49" lon="8"><ele>100</ele></trkpt><trkpt lat="49.1" lon="8.1"/></trkseg>'
        . '<trkseg><trkpt lat="50" lon="9"/><trkpt lat="50.1" lon="9.1"/></trkseg></trk></gpx>';

    public function testGeoJsonFeatureCollectionWithHoleAndMulti(): void
    {
        $json = json_encode(['type' => 'FeatureCollection', 'features' => [
            ['type' => 'Feature', 'id' => 7, 'properties' => ['name' => 'Park', 'n' => 3, 'ok' => true, 'obj' => ['x' => 1]], 'geometry' => [
                'type' => 'Polygon', 'coordinates' => [
                    [[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]],
                    [[2, 2], [4, 2], [4, 4], [2, 4], [2, 2]],
                ]]],
            ['type' => 'Feature', 'properties' => null, 'geometry' => ['type' => 'MultiPoint', 'coordinates' => [[1, 1], [2, 2]]]],
            ['type' => 'Feature', 'properties' => [], 'geometry' => null],
            ['type' => 'Feature', 'properties' => [], 'geometry' => ['type' => 'Point', 'coordinates' => [500, 1]]],
            'junk',
        ]], JSON_THROW_ON_ERROR);
        $result = (new GeoJsonImporter())->parse($json, new ImportLimits());
        self::assertCount(2, $result->features);
        self::assertCount(2, $result->features[0]->geometry->coordinates, 'the polygon hole is kept');
        self::assertSame(['name' => 'Park', 'n' => '3', 'ok' => 'true', 'id' => '7'], $result->features[0]->attributes);
        self::assertSame(3, $result->errorCount);
        self::assertContains('name', $result->columns);
    }

    public function testGeoJsonBareGeometryFeatureAndCollectionSplit(): void
    {
        $importer = new GeoJsonImporter();
        $bare = $importer->parse("\xEF\xBB\xBF" . '{"type":"Point","coordinates":[8,49]}', new ImportLimits());
        self::assertCount(1, $bare->features);
        $feature = $importer->parse('{"type":"Feature","properties":{},"geometry":{"type":"Point","coordinates":[8,49]}}', new ImportLimits());
        self::assertCount(1, $feature->features);
        $collection = $importer->parse('{"type":"Feature","properties":{"name":"x"},"geometry":{"type":"GeometryCollection","geometries":['
            . '{"type":"Point","coordinates":[1,1]},{"type":"LineString","coordinates":[[1,1],[2,2]]},{"type":"GeometryCollection","geometries":[]}]}}', new ImportLimits());
        self::assertCount(2, $collection->features);
        self::assertSame(1, $collection->errorCount);
        self::assertCount(1, $collection->warnings);
        $empty = $importer->parse('{"type":"Feature","properties":{},"geometry":{"type":"GeometryCollection","geometries":[]}}', new ImportLimits());
        self::assertSame(1, $empty->errorCount);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badGeoJson(): iterable
    {
        yield 'not json' => ['nope', ImportException::MALFORMED];
        yield 'scalar' => ['5', ImportException::MALFORMED];
        yield 'no type' => ['{"a":1}', ImportException::MALFORMED];
        yield 'no features list' => ['{"type":"FeatureCollection","features":{"a":1}}', ImportException::MALFORMED];
        yield 'too deep' => [str_repeat('[', 100) . str_repeat(']', 100), ImportException::TOO_DEEP];
    }

    #[DataProvider('badGeoJson')]
    public function testGeoJsonRejectsWholeFile(string $content, string $reason): void
    {
        try {
            (new GeoJsonImporter())->parse($content, new ImportLimits());
            self::fail('expected rejection');
        } catch (ImportException $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testLimitsRejectBeforeAnythingIsReturned(): void
    {
        $features = array_map(static fn (int $i): array => ['type' => 'Feature', 'properties' => [], 'geometry' => ['type' => 'Point', 'coordinates' => [$i / 10, 1]]], range(1, 5));
        $json = json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_THROW_ON_ERROR);
        $this->expectReason(ImportException::TOO_MANY_FEATURES, fn () => (new GeoJsonImporter())->parse($json, new ImportLimits(maxFeatures: 3)));
        $this->expectReason(ImportException::TOO_MANY_COORDINATES, fn () => (new GeoJsonImporter())->parse($json, new ImportLimits(maxCoordinates: 4)));
        $this->expectReason(ImportException::TOO_LARGE, fn () => (new GeoJsonImporter())->parse($json, new ImportLimits(maxBytes: 10)));
        $this->expectReason(ImportException::TOO_LARGE, fn () => (new GpxImporter())->parse(self::GPX, new ImportLimits(maxBytes: 10)));
        $this->expectReason(ImportException::TOO_LARGE, fn () => (new CsvImporter())->parse(self::GPX, new ImportLimits(maxBytes: 10)));
    }

    public function testErrorListIsCappedButCounted(): void
    {
        $features = array_fill(0, 10, ['type' => 'Feature', 'properties' => [], 'geometry' => null]);
        $json = json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_THROW_ON_ERROR);
        $result = (new GeoJsonImporter())->parse($json, new ImportLimits(maxErrors: 3));
        self::assertCount(3, $result->errors);
        self::assertSame(10, $result->errorCount);
        self::assertSame(['index' => 1, 'code' => 'no_geometry', 'message' => 'Feature has no geometry.', 'params' => []], $result->errors[0]->toArray());
    }

    public function testSplitGeometryCollectionMembersCarryTheirPartNumber(): void
    {
        $json = '{"type":"Feature","id":"a1","properties":{},"geometry":{"type":"GeometryCollection","geometries":['
            . '{"type":"Point","coordinates":[1,1]},{"type":"Point","coordinates":[2,2]},{"type":"LineString","coordinates":[[1,1],[2,2]]}]}}';
        $result = (new GeoJsonImporter())->parse($json, new ImportLimits());
        self::assertSame([0, 1, 2], array_map(static fn ($f): int => $f->part, $result->features));
        self::assertSame(['collection_split'], array_map(static fn ($w): string => $w->code, $result->warnings));
    }

    public function testGpxWaypointRouteAndTrackSegments(): void
    {
        $result = (new GpxImporter())->parse(self::GPX, new ImportLimits());
        self::assertSame(0, $result->errorCount);
        self::assertCount(3, $result->features);
        self::assertSame('Point', $result->features[0]->geometry->type->value);
        self::assertSame(['name' => 'Start', 'desc' => 'Hut', 'link' => 'https://example.org/a'], $result->features[0]->attributes);
        self::assertSame('LineString', $result->features[1]->geometry->type->value);
        self::assertSame('MultiLineString', $result->features[2]->geometry->type->value);
        self::assertCount(2, $result->features[2]->geometry->coordinates);
    }

    public function testGpx10AndSingleSegmentTrackAndInvalidEntries(): void
    {
        $gpx = "\xEF\xBB\xBF" . '<gpx version="1.0" xmlns="http://www.topografix.com/GPX/1/0"><wpt lat="x" lon="1"/>'
            . '<wpt lat="49" lon="8"><url>https://example.org</url></wpt><rte><rtept lat="1" lon="1"/></rte>'
            . '<trk><trkseg><trkpt lat="1" lon="1"/></trkseg></trk><trk><trkseg><trkpt lat="a" lon="1"/></trkseg></trk>'
            . '<trk><trkseg><trkpt lat="1" lon="1"/><trkpt lat="2" lon="2"/></trkseg></trk><rte><rtept lat="99" lon="1"/><rtept lat="1" lon="1"/></rte></gpx>';
        $result = (new GpxImporter())->parse($gpx, new ImportLimits());
        self::assertCount(2, $result->features);
        self::assertSame('https://example.org', $result->features[0]->attributes['link']);
        self::assertSame('LineString', $result->features[1]->geometry->type->value);
        self::assertSame(5, $result->errorCount);
    }

    public function testGpxRejectsXxeAndDtdAndBadDocuments(): void
    {
        $xxe = '<?xml version="1.0"?><!DOCTYPE gpx [<!ENTITY x SYSTEM "file:///etc/passwd">]><gpx><wpt lat="1" lon="1"><name>&x;</name></wpt></gpx>';
        $this->expectReason(ImportException::FORBIDDEN, fn () => (new GpxImporter())->parse($xxe, new ImportLimits()));
        $bomb = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;">]><gpx>&lol2;</gpx>';
        $this->expectReason(ImportException::FORBIDDEN, fn () => (new GpxImporter())->parse($bomb, new ImportLimits()));
        $network = '<?xml version="1.0"?><!DOCTYPE gpx SYSTEM "http://127.0.0.1:1/evil.dtd"><gpx></gpx>';
        $this->expectReason(ImportException::FORBIDDEN, fn () => (new GpxImporter())->parse($network, new ImportLimits()));
        $this->expectReason(ImportException::MALFORMED, fn () => (new GpxImporter())->parse('<gpx><wpt></gpx>', new ImportLimits()));
        $this->expectReason(ImportException::MALFORMED, fn () => (new GpxImporter())->parse('<kml/>', new ImportLimits()));
    }

    public function testGpxNestingLimitIsEnforced(): void
    {
        $nested = '<gpx>' . str_repeat('<extensions>', 40) . str_repeat('</extensions>', 40) . '<wpt lat="1" lon="1"/></gpx>';
        $this->expectReason(ImportException::TOO_DEEP, fn () => (new GpxImporter())->parse($nested, new ImportLimits(maxDepth: 32)));
        self::assertCount(1, (new GpxImporter())->parse($nested, new ImportLimits(maxDepth: 64))->features);
    }

    public function testCsvSemicolonAndDecimalComma(): void
    {
        $csv = "\xEF\xBB\xBF" . "Name;Breite;Länge;Info\n\"Hütte; Nord\";49,5;8,25;\"line1\nline2\"\n\nBad;x;1;\nShort;1\n";
        $result = (new CsvImporter())->parse($csv, new ImportLimits(), ['delimiter' => ';', 'decimal' => ',', 'lon_column' => 'Länge', 'lat_column' => 'Breite']);
        self::assertCount(1, $result->features);
        self::assertSame([8.25, 49.5], $result->features[0]->geometry->coordinates);
        self::assertSame('Hütte; Nord', $result->features[0]->attributes['Name']);
        self::assertSame(2, $result->errorCount);
        self::assertSame(['Name', 'Info'], $result->columns);
    }

    public function testCsvAutoDetectsColumnsAndRejectsBadOptions(): void
    {
        $result = (new CsvImporter())->parse("title,lat,LNG\nA,1.5,2.5\nB,99,1\n", new ImportLimits());
        self::assertCount(1, $result->features);
        self::assertSame(1, $result->errorCount);
        $german = (new CsvImporter())->parse("Name,Breitengrad,Längengrad\nA,49.5,8.5\n", new ImportLimits());
        self::assertSame([8.5, 49.5], $german->features[0]->geometry->coordinates);
        $tab = (new CsvImporter())->parse("x\ty\n1\t2\n", new ImportLimits(), ['delimiter' => "\t"]);
        self::assertCount(1, $tab->features);
        $this->expectReason(ImportException::INVALID_OPTION, fn () => (new CsvImporter())->parse("a,b\n1,2\n", new ImportLimits(), ['delimiter' => 'x']));
        $this->expectReason(ImportException::INVALID_OPTION, fn () => (new CsvImporter())->parse("a,b\n1,2\n", new ImportLimits(), ['decimal' => ';']));
        $this->expectReason(ImportException::INVALID_OPTION, fn () => (new CsvImporter())->parse("a,b\n1,2\n", new ImportLimits(), ['delimiter' => ',', 'decimal' => ',']));
        $this->expectReason(ImportException::INVALID_OPTION, fn () => (new CsvImporter())->parse("a,b\n1,2\n", new ImportLimits()));
        $this->expectReason(ImportException::INVALID_OPTION, fn () => (new CsvImporter())->parse("lat,lon\n1,2\n", new ImportLimits(), ['lon_column' => 'nope']));
        $this->expectReason(ImportException::MALFORMED, fn () => (new CsvImporter())->parse('', new ImportLimits()));
    }

    /** @param callable(): mixed $call */
    private function expectReason(string $reason, callable $call): void
    {
        try {
            $call();
            self::fail('expected ' . $reason);
        } catch (ImportException $e) {
            self::assertSame($reason, $e->reason);
        }
    }
}
