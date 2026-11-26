<?php

declare(strict_types=1);

namespace SWMaps\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWMaps\Core\Exception\NotFoundException;
use SWMaps\Core\Export\MapExporter;
use SWMaps\Core\Export\PackageImporter;
use SWMaps\Core\Import\GeoJsonImporter;
use SWMaps\Core\Import\ImportException;
use SWMaps\Core\Import\ImportLimits;
use SWMaps\Tests\Support\Fixture;

final class ExportImportTest extends TestCase
{
    private Fixture $f;
    private int $mapId;
    private int $layerId;
    private int $categoryId;

    protected function setUp(): void
    {
        $this->f = new Fixture();
        $this->mapId = $this->f->mapService->create(['title' => 'Wanderkarte', 'description' => 'Schöne Wege', 'settings' => ['providerId' => 7, 'height' => 500]], 3)->id;
        $this->categoryId = $this->f->categoryService->create(['title' => 'Hütten', 'style' => ['color' => '#00aa00', 'icon' => 'builtin:home']])->id;
        $this->layerId = $this->f->layerService->create($this->mapId, ['title' => 'Orte', 'style' => ['color' => '#112233', 'rules' => [['categoryId' => $this->categoryId, 'style' => ['color' => '#ff0000']]]]])->id;
        $this->f->featureService->create($this->layerId, [
            'geometry' => ['type' => 'Point', 'coordinates' => [8.4, 49.0]],
            'external_id' => 'h1',
            'properties' => ['title' => '=HYPERLINK("x")', 'description' => 'Text', 'address' => 'Weg 1', 'link' => 'https://example.org', 'icon' => 'media:55', 'image_id' => 9, 'fields' => [['label' => 'Öffnung', 'value' => '9-17']]],
            'categories' => [$this->categoryId],
            'style' => ['color' => '#0000ff'],
        ]);
        $this->f->featureService->create($this->layerId, ['geometry' => ['type' => 'LineString', 'coordinates' => [[8, 49], [8.1, 49.1]]], 'properties' => ['title' => 'Pfad']]);
    }

    private function exporter(): MapExporter
    {
        return new MapExporter($this->f->maps, $this->f->layers, $this->f->features, $this->f->categories, $this->f->clock);
    }

    private function importer(?ImportLimits $limits = null, ?Fixture $target = null): PackageImporter
    {
        $t = $target ?? $this->f;

        return new PackageImporter($t->maps, $t->layers, $t->features, $t->categories, $t->tx, $t->clock, $t->events, $limits ?? new ImportLimits());
    }

    public function testGeoJsonExportRoundTripsThroughTheImporter(): void
    {
        $out = $this->exporter()->geojson($this->mapId);
        self::assertSame('application/geo+json', $out['mime_type']);
        self::assertStringEndsWith('.geojson', $out['filename']);
        $data = json_decode($out['content'], true);
        self::assertCount(2, $data['features']);
        self::assertSame(['Hütten'], $data['features'][0]['properties']['categories']);
        self::assertSame(['Öffnung' => '9-17'], $data['features'][0]['properties']['fields']);
        $parsed = (new GeoJsonImporter())->parse($out['content'], new ImportLimits());
        self::assertSame([2, 0], [count($parsed->features), $parsed->errorCount]);
        self::assertSame('h1', $parsed->features[0]->attributes['external_id']);
        self::assertCount(2, json_decode($this->exporter()->geojson($this->mapId, $this->layerId)['content'], true)['features']);
    }

    public function testCsvExportsPointsOnlyAndNeutralisesFormulas(): void
    {
        $out = $this->exporter()->csv($this->mapId);
        $rows = array_map(static fn (string $l): array => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", $out['content'])));
        self::assertCount(2, $rows, 'header plus the single point');
        self::assertSame(['title', 'description', 'address', 'link', 'lon', 'lat', 'external_id', 'layer', 'categories'], $rows[0]);
        self::assertSame("'=HYPERLINK(\"x\")", $rows[1][0]);
        self::assertSame(['8.4', '49'], [$rows[1][4], $rows[1][5]]);
        self::assertSame(['h1', 'Orte', 'Hütten'], [$rows[1][6], $rows[1][7], $rows[1][8]]);
    }

    public function testExportOfUnknownMapOrLayerIsNotFound(): void
    {
        foreach ([fn () => $this->exporter()->geojson(999), fn () => $this->exporter()->csv(999), fn () => $this->exporter()->package([999]), fn () => $this->exporter()->geojson($this->mapId, 999)] as $call) {
            try {
                $call();
                self::fail('expected NotFoundException');
            } catch (NotFoundException) {
                self::assertTrue(true);
            }
        }
    }

    public function testPackageHasNoCredentialsOrMediaAndIsVersioned(): void
    {
        $out = $this->exporter()->package([$this->mapId]);
        $json = json_decode($out['content'], true);
        self::assertSame(['sw-maps-package', 1], [$json['format'], $json['version']]);
        self::assertNull($json['maps'][0]['settings']['providerId']);
        self::assertSame(500, $json['maps'][0]['settings']['height']);
        $feature = $json['maps'][0]['layers'][0]['features'][0];
        self::assertNull($feature['properties']['image_id'] ?? null);
        self::assertSame('builtin:pin', $feature['properties']['icon']);
        self::assertSame(['huetten'], $feature['categories']);
        self::assertSame('huetten', $json['maps'][0]['layers'][0]['style']['rules'][0]['categorySlug']);
        self::assertStringNotContainsString('categoryId', $out['content']);
        self::assertStringNotContainsString('media:', $out['content']);
        self::assertSame('huetten', $json['categories'][0]['slug']);
    }

    public function testImportCreatesDraftMapsWithLayersFeaturesAndCategoriesOnAnotherSite(): void
    {
        $package = $this->exporter()->package()['content'];
        $other = new Fixture();
        $report = $this->importer(null, $other)->import($package, 42);
        self::assertSame([1, 0, true], [$report['categories_created'], $report['categories_matched'], $report['media_relink_hint']]);
        self::assertSame([['id' => $report['maps'][0]['id'], 'title' => 'Wanderkarte', 'layers' => 1, 'features' => 2]], $report['maps']);
        $map = $other->maps->find($report['maps'][0]['id']);
        self::assertSame(['draft', 42, null], [$map?->status->value, $map?->createdBy, $map?->settings->providerId]);
        $layer = $other->layers->forMap($map->id)[0];
        $category = $other->categories->all()[0];
        self::assertSame($category->id, $layer->style['rules'][0]['categoryId']);
        $features = $other->features->forLayers([$layer->id]);
        self::assertSame([$category->id], $features[0]->categoryIds);
        self::assertSame(['h1', 'builtin:pin', null], [$features[0]->externalId, $features[0]->properties->icon, $features[0]->properties->imageId]);
        self::assertSame([['label' => 'Öffnung', 'value' => '9-17']], $features[0]->properties->fields);
        self::assertContains('layer:' . $layer->id . ':' . $map->id, $other->events->log);
        // A second import reuses the category and gets a distinct slug.
        $again = $this->importer(null, $other)->import($package, 42);
        self::assertSame([0, 1], [$again['categories_created'], $again['categories_matched']]);
        self::assertNotSame($map->slug, $other->maps->find($again['maps'][0]['id'])?->slug);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badPackages(): iterable
    {
        $ok = ['format' => 'sw-maps-package', 'version' => 1, 'categories' => [], 'maps' => [['title' => 'M', 'layers' => []]]];
        yield 'not json' => ['nope', ImportException::MALFORMED];
        yield 'too deep' => [str_repeat('[', 100) . str_repeat(']', 100), ImportException::TOO_DEEP];
        yield 'wrong format' => [json_encode(['format' => 'x']), ImportException::MALFORMED];
        yield 'no version' => [json_encode(['format' => 'sw-maps-package', 'maps' => []]), ImportException::MALFORMED];
        yield 'newer version' => [json_encode(['version' => 99] + $ok), ImportException::UNSUPPORTED];
        yield 'no maps' => [json_encode(['maps' => []] + $ok), ImportException::MALFORMED];
        yield 'too many maps' => [json_encode(['maps' => array_fill(0, 101, $ok['maps'][0])] + $ok), ImportException::MALFORMED];
        yield 'map not object' => [json_encode(['maps' => ['x']] + $ok), ImportException::MALFORMED];
        yield 'empty title' => [json_encode(['maps' => [['title' => '', 'layers' => []]]] + $ok), ImportException::MALFORMED];
        yield 'bad viewport' => [json_encode(['maps' => [['title' => 'M', 'layers' => [], 'viewport' => ['zoom' => 'x']]]] + $ok), ImportException::MALFORMED];
        yield 'bad categories' => [json_encode(['categories' => 'x'] + $ok), ImportException::MALFORMED];
        yield 'bad category slug' => [json_encode(['categories' => [['slug' => 'Bad Slug', 'title' => 'x']]] + $ok), ImportException::MALFORMED];
        yield 'bad category' => [json_encode(['categories' => [['slug' => 'ok']]] + $ok), ImportException::MALFORMED];
        yield 'layer not object' => [json_encode(['maps' => [['title' => 'M', 'layers' => ['x']]]] + $ok), ImportException::MALFORMED];
        yield 'bad layer status' => [json_encode(['maps' => [['title' => 'M', 'layers' => [['title' => 'L', 'status' => 'zzz', 'features' => []]]]]] + $ok), ImportException::MALFORMED];
        yield 'feature without geometry' => [json_encode(['maps' => [['title' => 'M', 'layers' => [['title' => 'L', 'features' => [['properties' => []]]]]]]] + $ok), ImportException::MALFORMED];
        yield 'invalid coordinates' => [json_encode(['maps' => [['title' => 'M', 'layers' => [['title' => 'L', 'features' => [['geometry' => ['type' => 'Point', 'coordinates' => [999, 1]]]]]]]]] + $ok), ImportException::MALFORMED];
        yield 'invalid link' => [json_encode(['maps' => [['title' => 'M', 'layers' => [['title' => 'L', 'features' => [['geometry' => ['type' => 'Point', 'coordinates' => [1, 1]], 'properties' => ['link' => 'javascript:alert(1)']]]]]]]] + $ok), ImportException::MALFORMED];
        yield 'duplicate external id' => [json_encode(['maps' => [['title' => 'M', 'layers' => [['title' => 'L', 'features' => [
            ['geometry' => ['type' => 'Point', 'coordinates' => [1, 1]], 'external_id' => 'A'],
            ['geometry' => ['type' => 'Point', 'coordinates' => [2, 2]], 'external_id' => 'a'],
        ]]]]]] + $ok), ImportException::MALFORMED];
    }

    #[DataProvider('badPackages')]
    public function testRejectedPackagesWriteNothing(string $content, string $reason): void
    {
        $target = new Fixture();
        try {
            $this->importer(null, $target)->import($content, 1);
            self::fail('expected rejection');
        } catch (ImportException $e) {
            self::assertSame($reason, $e->reason);
        }
        self::assertSame([], $target->maps->items);
        self::assertSame([], $target->categories->items);
    }

    public function testLimitsApplyToSizeAndLocations(): void
    {
        $package = $this->exporter()->package()['content'];
        foreach ([[new ImportLimits(maxBytes: 100), ImportException::TOO_LARGE], [new ImportLimits(maxFeatures: 1), ImportException::TOO_MANY_FEATURES]] as [$limits, $reason]) {
            try {
                $this->importer($limits, new Fixture())->import($package, 1);
                self::fail('expected rejection');
            } catch (ImportException $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    public function testImportAcceptsBomAndMediaIsAlwaysDropped(): void
    {
        $package = json_decode($this->exporter()->package()['content'], true);
        $package['maps'][0]['layers'][0]['features'][0]['properties']['icon'] = 'media:77';
        $package['maps'][0]['layers'][0]['features'][0]['properties']['image_id'] = 12;
        $target = new Fixture();
        $report = $this->importer(null, $target)->import("\xEF\xBB\xBF" . json_encode($package), 1);
        $feature = array_values($target->features->items)[0];
        self::assertSame(['builtin:pin', null], [$feature->properties->icon, $feature->properties->imageId]);
        self::assertTrue($report['media_relink_hint']);
    }

    public function testAllMapsAreExportedInOnePackage(): void
    {
        $this->f->mapService->create(['title' => 'Zweite'], 1);
        $json = json_decode($this->exporter()->package()['content'], true);
        self::assertCount(2, $json['maps']);
        self::assertStringStartsWith('sw-maps-', $this->exporter()->package()['filename']);
        self::assertStringStartsWith('wanderkarte-', $this->exporter()->package([$this->mapId])['filename']);
        self::assertStringStartsWith('mein-export-', $this->exporter()->package(null, 'mein-export')['filename']);
    }
}
