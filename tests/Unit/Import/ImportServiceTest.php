<?php

declare(strict_types=1);

namespace SWMaps\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use SWMaps\Core\Domain\Feature;
use SWMaps\Core\Exception\NotFoundException;
use SWMaps\Core\Exception\ValidationException;
use SWMaps\Core\Import\FieldMapper;
use SWMaps\Core\Import\ImportException;
use SWMaps\Core\Import\ImportLimits;
use SWMaps\Core\Import\ImportService;
use SWMaps\Tests\Support\Fixture;

final class ImportServiceTest extends TestCase
{
    private Fixture $f;
    private int $mapId;

    protected function setUp(): void
    {
        $this->f = new Fixture();
        $this->mapId = $this->f->mapService->create(['title' => 'M'], 1)->id;
    }

    private function service(int $maxPerLayer = 5000, ?ImportLimits $limits = null): ImportService
    {
        return new ImportService($this->f->maps, $this->f->layers, $this->f->features, $this->f->tx, $this->f->clock, $this->f->events, $limits ?? new ImportLimits(), $maxPerLayer);
    }

    /** @param list<array<string, mixed>> $features */
    private static function geojson(array $features): string
    {
        return json_encode(['type' => 'FeatureCollection', 'features' => array_map(static fn (array $p): array => [
            'type' => 'Feature',
            'properties' => $p['props'] ?? [],
            'geometry' => $p['geometry'] ?? ['type' => 'Point', 'coordinates' => [8, 49]],
        ], $features)], JSON_THROW_ON_ERROR);
    }

    public function testNewLayerCreatesLayerAndFeatures(): void
    {
        $content = self::geojson([['props' => ['name' => 'A', 'desc' => 'x', 'id' => 'a1', 'photo' => 'p.jpg']], ['props' => ['name' => 'B', 'url' => 'javascript:alert(1)']]]);
        $report = $this->service()->run($this->mapId, ['format' => 'geojson', 'content' => $content, 'layer_title' => 'Huts']);
        self::assertTrue($report['imported']);
        self::assertSame([2, 0, 0], [$report['created'], $report['updated'], $report['error_count']]);
        self::assertTrue($report['media_relink_hint']);
        self::assertCount(1, $report['warnings']);
        self::assertSame(['code' => 'field_invalid', 'params' => ['field' => 'link']], array_intersect_key($report['warnings'][0], ['code' => 1, 'params' => 1]));
        $layers = $this->f->layers->forMap($this->mapId);
        self::assertSame('Huts', $layers[0]->title);
        $features = $this->f->features->forLayers([$layers[0]->id]);
        self::assertSame(['A', 'a1'], [$features[0]->properties->title, $features[0]->externalId]);
        self::assertNull($features[1]->properties->link);
        self::assertContains('layer:' . $layers[0]->id . ':' . $this->mapId, $this->f->events->log);
    }

    public function testDryRunWritesNothing(): void
    {
        $report = $this->service()->run($this->mapId, ['format' => 'geojson', 'content' => self::geojson([['props' => ['name' => 'A']]]), 'dry_run' => true]);
        self::assertFalse($report['imported']);
        self::assertSame('A', $report['preview'][0]['title']);
        self::assertSame(['title' => 'name'], $report['mapping']);
        self::assertSame([], $this->f->layers->forMap($this->mapId));
        self::assertSame(0, $this->f->tx->runs);
    }

    public function testInvalidEntriesRejectUnlessSkipConfirmed(): void
    {
        $content = self::geojson([['props' => ['name' => 'ok']], ['geometry' => ['type' => 'Point', 'coordinates' => [999, 1]]]]);
        try {
            $this->service()->run($this->mapId, ['format' => 'geojson', 'content' => $content]);
            self::fail('expected rejection');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('file', $e->errors());
        }
        self::assertSame([], $this->f->layers->forMap($this->mapId), 'no silent partial import');
        $report = $this->service()->run($this->mapId, ['format' => 'geojson', 'content' => $content, 'skip_invalid' => true]);
        self::assertSame([1, 1], [$report['created'], $report['skipped']]);
        self::assertSame(2, $report['total']);
    }

    public function testLimitExcessRejectsBeforeWriting(): void
    {
        $content = self::geojson([['props' => ['name' => 'a']], ['props' => ['name' => 'b']], ['props' => ['name' => 'c']]]);
        foreach ([[2, null], [5000, new ImportLimits(maxFeatures: 2)]] as [$perLayer, $limits]) {
            try {
                $this->service($perLayer, $limits)->run($this->mapId, ['format' => 'geojson', 'content' => $content]);
                self::fail('expected rejection');
            } catch (ImportException $e) {
                self::assertSame(ImportException::TOO_MANY_FEATURES, $e->reason);
            }
        }
        self::assertSame([], $this->f->layers->forMap($this->mapId));
        self::assertSame([], $this->f->features->items);
    }

    public function testAppendCountsExistingAgainstLimit(): void
    {
        $layer = $this->f->layerService->create($this->mapId, ['title' => 'L']);
        $this->f->featureService->create($layer->id, ['geometry' => ['type' => 'Point', 'coordinates' => [1, 1]], 'properties' => ['title' => 'old']]);
        $content = self::geojson([['props' => ['name' => 'a']], ['props' => ['name' => 'b']]]);
        $this->expectException(ImportException::class);
        try {
            $this->service(2)->run($this->mapId, ['format' => 'geojson', 'content' => $content, 'mode' => 'append', 'layer_id' => $layer->id]);
        } finally {
            self::assertCount(1, $this->f->features->items);
        }
    }

    public function testAppendRejectsExternalIdsThatAlreadyExist(): void
    {
        $layer = $this->f->layerService->create($this->mapId, ['title' => 'L']);
        $this->f->featureService->create($layer->id, ['geometry' => ['type' => 'Point', 'coordinates' => [1, 1]], 'external_id' => 'a1']);
        $content = self::geojson([['props' => ['id' => 'a1', 'name' => 'dup']], ['props' => ['id' => 'b2', 'name' => 'new']]]);
        $args = ['format' => 'geojson', 'content' => $content, 'mode' => 'append', 'layer_id' => $layer->id];
        $dry = $this->service()->run($this->mapId, $args + ['dry_run' => true]);
        self::assertSame([1, 1], [$dry['created'], $dry['error_count']]);
        self::assertSame('external_id_exists', $dry['errors'][0]['code']);
        try {
            $this->service()->run($this->mapId, $args);
            self::fail('expected the invalid entry to block the import');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('file', $e->errors());
        }
        self::assertCount(1, $this->f->features->items);
        $report = $this->service()->run($this->mapId, $args + ['skip_invalid' => true]);
        self::assertSame([1, 1], [$report['created'], $report['skipped']]);
        self::assertCount(2, $this->f->features->items);
    }

    public function testAppendKeepsExistingFeatures(): void
    {
        $layer = $this->f->layerService->create($this->mapId, ['title' => 'L']);
        $this->f->featureService->create($layer->id, ['geometry' => ['type' => 'Point', 'coordinates' => [1, 1]]]);
        $report = $this->service()->run($this->mapId, ['format' => 'geojson', 'content' => self::geojson([['props' => ['name' => 'n']]]), 'mode' => 'append', 'layer_id' => $layer->id]);
        self::assertSame($layer->id, $report['layer_id']);
        self::assertCount(2, $this->f->features->items);
    }

    public function testUpsertUpdatesByExternalIdAndKeepsImageAndIcon(): void
    {
        $layer = $this->f->layerService->create($this->mapId, ['title' => 'L']);
        $kept = $this->f->featureService->create($layer->id, [
            'external_id' => 'a1',
            'geometry' => ['type' => 'Point', 'coordinates' => [1, 1]],
            'properties' => ['title' => 'Old', 'description' => 'keep me', 'image_id' => 5, 'icon' => 'builtin:star'],
        ]);
        $content = self::geojson([
            ['props' => ['id' => 'a1', 'name' => 'New'], 'geometry' => ['type' => 'Point', 'coordinates' => [2, 2]]],
            ['props' => ['id' => 'b2', 'name' => 'Fresh']],
            ['props' => ['name' => 'No id']],
        ]);
        $report = $this->service()->run($this->mapId, ['format' => 'geojson', 'content' => $content, 'mode' => 'upsert', 'layer_id' => $layer->id]);
        self::assertSame([2, 1], [$report['created'], $report['updated']]);
        $after = $this->f->features->find($kept->id);
        self::assertInstanceOf(Feature::class, $after);
        self::assertSame(['New', 'keep me', 5, 'builtin:star', [2.0, 2.0]], [$after->properties->title, $after->properties->description, $after->properties->imageId, $after->properties->icon, $after->geometry->coordinates]);
        self::assertCount(3, $this->f->features->items);
    }

    public function testDuplicateExternalIdsInFileAreErrors(): void
    {
        $content = self::geojson([['props' => ['id' => 'x', 'name' => 'a']], ['props' => ['id' => 'x', 'name' => 'b']], ['props' => ['id' => str_repeat('z', 200)]]]);
        $report = $this->service()->run($this->mapId, ['format' => 'geojson', 'content' => $content, 'dry_run' => true]);
        self::assertSame(2, $report['error_count']);
        self::assertSame('Duplicate external ID in the file.', $report['errors'][0]['message']);
        self::assertSame(['duplicate_external_id', 'external_id_too_long'], array_column($report['errors'], 'code'));
    }

    public function testSplitCollectionMembersGetDistinctExternalIds(): void
    {
        $content = '{"type":"FeatureCollection","features":[{"type":"Feature","properties":{"id":"a1","name":"X"},"geometry":{"type":"GeometryCollection","geometries":['
            . '{"type":"Point","coordinates":[1,1]},{"type":"Point","coordinates":[2,2]}]}}]}';
        $report = $this->service()->run($this->mapId, ['format' => 'geojson', 'content' => $content]);
        self::assertSame([2, 0], [$report['created'], $report['error_count']]);
        $ids = array_map(static fn (Feature $f): ?string => $f->externalId, array_values($this->f->features->items));
        self::assertSame(['a1', 'a1#2'], $ids);
        $again = $this->service()->run($this->mapId, ['format' => 'geojson', 'content' => $content, 'mode' => 'upsert', 'layer_id' => $report['layer_id']]);
        self::assertSame([2, 0], [$again['updated'], $again['created']], 'a re-import updates both parts');
    }

    public function testReplaceNeedsConfirmationAndDeletesOldContent(): void
    {
        $layer = $this->f->layerService->create($this->mapId, ['title' => 'L']);
        $this->f->featureService->create($layer->id, ['geometry' => ['type' => 'Point', 'coordinates' => [1, 1]]]);
        $args = ['format' => 'geojson', 'content' => self::geojson([['props' => ['name' => 'n']]]), 'mode' => 'replace', 'layer_id' => $layer->id];
        try {
            $this->service()->run($this->mapId, $args);
            self::fail('expected confirmation error');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('confirm_replace', $e->errors());
        }
        self::assertCount(1, $this->f->features->items);
        $dry = $this->service()->run($this->mapId, $args + ['dry_run' => true]);
        self::assertFalse($dry['imported']);
        self::assertCount(1, $this->f->features->items);
        $this->service()->run($this->mapId, $args + ['confirm_replace' => true]);
        $items = array_values($this->f->features->items);
        self::assertCount(1, $items);
        self::assertSame('n', $items[0]->properties->title);
    }

    public function testRequestValidation(): void
    {
        $content = self::geojson([['props' => []]]);
        $cases = [
            [['format' => 'kml', 'content' => $content], ValidationException::class],
            [['format' => 'geojson'], ValidationException::class],
            [['format' => 'geojson', 'content' => $content, 'mode' => 'merge'], ValidationException::class],
            [['format' => 'geojson', 'content' => $content, 'mode' => 'append'], ValidationException::class],
            [['format' => 'geojson', 'content' => $content, 'mode' => 'append', 'layer_id' => 999], NotFoundException::class],
            [['format' => 'geojson', 'content' => $content, 'mapping' => ['title' => 'nope']], ValidationException::class],
            [['format' => 'geojson', 'content' => $content, 'mapping' => ['bogus' => 'x']], ValidationException::class],
            [['format' => 'geojson', 'content' => '{"type":"FeatureCollection","features":[]}'], ValidationException::class],
        ];
        foreach ($cases as [$input, $class]) {
            try {
                $this->service()->run($this->mapId, $input);
                self::fail('expected ' . $class);
            } catch (\Throwable $e) {
                self::assertInstanceOf($class, $e);
            }
        }
        $this->expectException(NotFoundException::class);
        $this->service()->run(9999, ['format' => 'geojson', 'content' => $content]);
    }

    public function testLayerOfAnotherMapIsRejected(): void
    {
        $other = $this->f->mapService->create(['title' => 'O'], 1)->id;
        $layer = $this->f->layerService->create($other, ['title' => 'L']);
        $this->expectException(NotFoundException::class);
        $this->service()->run($this->mapId, ['format' => 'geojson', 'content' => self::geojson([['props' => []]]), 'mode' => 'append', 'layer_id' => $layer->id]);
    }

    public function testExplicitMappingAndGpxCsvFormats(): void
    {
        $csv = "Ort,Lat,Lon,Kennung\nHütte,49,8,k1\n";
        $report = $this->service()->run($this->mapId, [
            'format' => 'csv',
            'content' => $csv,
            'mapping' => ['title' => 'Ort', 'external_id' => 'Kennung', 'description' => ''],
        ]);
        self::assertSame(1, $report['created']);
        $feature = array_values($this->f->features->items)[0];
        self::assertSame(['Hütte', 'k1'], [$feature->properties->title, $feature->externalId]);
        $gpx = '<gpx><wpt lat="49" lon="8"><name>W</name></wpt></gpx>';
        self::assertSame(1, $this->service()->run($this->mapId, ['format' => 'gpx', 'content' => $gpx])['created']);
        $semi = "name;lat;lon\nX;49,5;8,5\n";
        self::assertSame(1, $this->service()->run($this->mapId, ['format' => 'csv', 'content' => $semi, 'options' => ['delimiter' => ';', 'decimal' => ',']])['created']);
    }

    public function testExtraColumnsBecomeStructuredFieldsAndUpsertKeepsOthers(): void
    {
        $csv = "name,lat,lon,id,Hours,Phone\nHut,49,8,h1,9-17,\n";
        $report = $this->service()->run($this->mapId, ['format' => 'csv', 'content' => $csv, 'extra_columns' => ['Hours', 'Phone']]);
        $feature = array_values($this->f->features->items)[0];
        self::assertSame([['label' => 'Hours', 'value' => '9-17']], $feature->properties->fields, 'empty values are skipped');
        $this->f->features->save($feature->with(null, \SWMaps\Core\Domain\FeatureProperties::fromArray(['fields' => [['label' => 'Own', 'value' => 'kept']]], $feature->properties)));
        $this->service()->run($this->mapId, ['format' => 'csv', 'content' => "name,lat,lon,id\nHut2,49,8,h1\n", 'mode' => 'upsert', 'layer_id' => $report['layer_id']]);
        $after = array_values($this->f->features->items)[0];
        self::assertSame([['label' => 'Own', 'value' => 'kept']], $after->properties->fields, 'a file without extra columns keeps existing fields');
        foreach ([['x'], 'x', ['nope'], array_fill(0, 11, 'Hours')] as $bad) {
            try {
                $this->service()->run($this->mapId, ['format' => 'csv', 'content' => $csv, 'extra_columns' => $bad, 'dry_run' => true]);
                self::fail('expected ValidationException');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('extra_columns', $e->errors());
            }
        }
    }

    public function testFieldMapperSuggestAndApply(): void
    {
        self::assertSame(['title' => 'Name', 'link' => 'Website', 'external_id' => 'ID'], FieldMapper::suggest(['Name', 'ID', 'Website', 'x']));
        self::assertSame(['title' => 'T'], FieldMapper::apply(['a' => 'T', 'b' => ''], ['title' => 'a', 'description' => 'b', 'link' => '', 'bogus' => 'a']));
        self::assertFalse(FieldMapper::hasMediaColumns(['name']));
    }
}
