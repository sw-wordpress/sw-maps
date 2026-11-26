<?php

declare(strict_types=1);

namespace SWMaps\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SWMaps\Core\Domain\MapStatus;
use SWMaps\Core\Exception\InvalidGeometryException;
use SWMaps\Core\Exception\NotFoundException;
use SWMaps\Core\Exception\ValidationException;
use SWMaps\Tests\Support\Fixture;

final class LayerFeatureServiceTest extends TestCase
{
    private Fixture $f;
    private int $mapId;

    protected function setUp(): void
    {
        $this->f = new Fixture();
        $this->mapId = $this->f->mapService->create(['title' => 'M'], 1)->id;
    }

    public function testLayerLifecycle(): void
    {
        $a = $this->f->layerService->create($this->mapId, ['title' => 'A']);
        $b = $this->f->layerService->create($this->mapId, ['title' => 'B', 'status' => 'draft', 'visible' => false, 'style' => ['color' => '#ff0000']]);
        self::assertSame([0, 1], [$a->sortOrder, $b->sortOrder]);
        self::assertSame(MapStatus::Draft, $b->status);
        self::assertSame(['color' => '#ff0000'], $b->style);
        $u = $this->f->layerService->update($b->id, ['title' => 'B2', 'sort_order' => 0, 'status' => 'published']);
        self::assertSame(['B2', 0, true], [$u->title, $u->sortOrder, $u->isPublished()]);
        self::assertCount(2, $this->f->layerService->forMap($this->mapId));
        $this->f->layerService->delete($a->id);
        self::assertCount(1, $this->f->layerService->forMap($this->mapId));
        self::assertContains('layer:' . $a->id . ':' . $this->mapId, $this->f->events->log);
    }

    public function testFeaturesReportTheirLineLength(): void
    {
        $layer = $this->f->layerService->create($this->mapId, ['title' => 'T']);
        $line = $this->f->featureService->create($layer->id, ['geometry' => ['type' => 'LineString', 'coordinates' => [[0, 0], [0, 1]]]]);
        $point = $this->f->featureService->create($layer->id, ['geometry' => ['type' => 'Point', 'coordinates' => [0, 0]]]);
        self::assertEqualsWithDelta(111195.0, $line->toArray()['length_m'], 100.0);
        self::assertSame(0.0, $point->toArray()['length_m']);
    }

    public function testBoundsQueryWrapsAroundTheAntimeridian(): void
    {
        $layer = $this->f->layerService->create($this->mapId, ['title' => 'W']);
        foreach ([[179.5, 0], [-179.5, 0], [0, 0]] as [$lon, $lat]) {
            $this->f->featureService->create($layer->id, ['geometry' => ['type' => 'Point', 'coordinates' => [$lon, $lat]]]);
        }
        $wrapped = $this->f->features->inBounds([$layer->id], [170.0, -10.0, -170.0, 10.0], [], 1, 10);
        self::assertSame(2, $wrapped['total']);
        $plain = $this->f->features->inBounds([$layer->id], [-10.0, -10.0, 10.0, 10.0], [], 1, 10);
        self::assertSame(1, $plain['total']);
    }

    public function testLayerStyleRulesAreValidated(): void
    {
        $rules = [['categoryId' => 3, 'style' => ['color' => '#ff0000', 'weight' => 5]]];
        $layer = $this->f->layerService->create($this->mapId, ['title' => 'R', 'style' => ['color' => '#111111', 'rules' => $rules]]);
        self::assertSame($rules, $layer->style['rules']);
        self::assertSame($rules, $this->f->layerService->update($layer->id, ['style' => ['rules' => $rules]])->style['rules']);
        $bad = [
            'x',
            array_fill(0, 11, $rules[0]),
            [['categoryId' => 'a', 'style' => []]],
            [['categoryId' => 0, 'style' => []]],
            [['categoryId' => 1]],
            [['categoryId' => 1, 'style' => ['rules' => []]]],
            [['categoryId' => 1, 'style' => ['color' => '<script>']]],
            ['categoryId' => 1],
        ];
        foreach ($bad as $value) {
            try {
                $this->f->layerService->update($layer->id, ['style' => ['rules' => $value]]);
                self::fail('expected ValidationException');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testLayerErrors(): void
    {
        $l = $this->f->layerService->create($this->mapId, ['title' => 'A']);
        foreach ([['title' => ''], ['status' => 'x'], ['status' => 1], ['sort_order' => -1], ['visible' => 'no'], ['style' => ['bad key' => 1]], ['style' => ['c' => 'url(javascript:x);']], ['style' => ['c' => []]]] as $patch) {
            try {
                $this->f->layerService->update($l->id, $patch);
                self::fail('accepted ' . json_encode($patch));
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(NotFoundException::class);
        $this->f->layerService->create(999, ['title' => 'x']);
    }

    public function testLayerForUnknownMap(): void
    {
        $this->expectException(NotFoundException::class);
        $this->f->layerService->forMap(404);
    }

    public function testFeatureCrudAndPagination(): void
    {
        $l = $this->f->layerService->create($this->mapId, ['title' => 'A']);
        for ($i = 0; $i < 5; ++$i) {
            $this->f->featureService->create($l->id, ['geometry' => ['type' => 'Point', 'coordinates' => [$i, $i]], 'properties' => ['title' => 'P' . $i]]);
        }
        $page = $this->f->featureService->page($l->id, 2, 2);
        self::assertSame(5, $page['total']);
        self::assertCount(2, $page['items']);
        $first = $page['items'][0];
        $u = $this->f->featureService->update($first->id, ['geometry' => ['type' => 'Point', 'coordinates' => [9, 9]], 'properties' => ['description' => 'D'], 'style' => ['color' => 'red']]);
        self::assertSame([9.0, 9.0], $u->geometry->coordinates);
        self::assertSame($first->properties->title, $u->properties->title);
        self::assertSame('D', $u->properties->description);
        $this->f->featureService->delete($first->id);
        self::assertSame(4, $this->f->featureService->page($l->id, 1, 100)['total']);
        self::assertSame(500, count($this->f->featureService->page($l->id, 0, 9999)['items']) + 496);
    }

    public function testFeatureRejectsInvalidGeometryAndMissingGeometry(): void
    {
        $l = $this->f->layerService->create($this->mapId, ['title' => 'A']);
        foreach ([['geometry' => ['type' => 'Point', 'coordinates' => [181, 0]]], []] as $input) {
            try {
                $this->f->featureService->create($l->id, $input);
                self::fail('accepted');
            } catch (InvalidGeometryException) {
                self::assertTrue(true);
            }
        }
        self::assertSame([], $this->f->features->items);
    }

    public function testFeatureNotFoundAndEmptyExternalId(): void
    {
        $l = $this->f->layerService->create($this->mapId, ['title' => 'A']);
        $f = $this->f->featureService->create($l->id, ['geometry' => ['type' => 'Point', 'coordinates' => [1, 1]], 'external_id' => '']);
        self::assertNull($f->externalId);
        $this->expectException(NotFoundException::class);
        $this->f->featureService->get(999);
    }

    public function testFeaturePageOfUnknownLayer(): void
    {
        $this->expectException(NotFoundException::class);
        $this->f->featureService->page(404, 1, 10);
    }

    public function testFeatureToArrayIsJsonSerialisable(): void
    {
        $l = $this->f->layerService->create($this->mapId, ['title' => 'A']);
        $f = $this->f->featureService->create($l->id, ['geometry' => ['type' => 'Point', 'coordinates' => [1, 1]]]);
        $json = json_encode($f->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"style":{}', $json);
        self::assertStringContainsString('"style":{}', json_encode($l->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame($this->mapId, $this->f->mapService->get($this->mapId)->toArray()['id']);
    }

    public function testNullMeansNotGivenForOptionalFields(): void
    {
        $l = $this->f->layerService->create($this->mapId, ['title' => 'A']);
        $f = $this->f->featureService->create($l->id, ['geometry' => ['type' => 'Point', 'coordinates' => [1, 1]], 'external_id' => null]);
        self::assertNull($f->externalId);
        $m = $this->f->mapService->update($this->mapId, ['slug' => null, 'description' => null])['map'];
        self::assertSame('m', $m->slug);
        $this->expectException(ValidationException::class);
        $this->f->layerService->create($this->mapId, ['title' => null]);
    }
}
