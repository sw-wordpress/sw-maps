<?php

declare(strict_types=1);

namespace SWMaps\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SWMaps\Core\Application\PublicFeatureService;
use SWMaps\Tests\Support\Fixture;

final class PublicFeatureServiceTest extends TestCase
{
    private Fixture $f;
    private PublicFeatureService $service;
    private int $mapId;
    private int $layerId;

    protected function setUp(): void
    {
        $this->f = new Fixture();
        $this->service = new PublicFeatureService($this->f->maps, $this->f->layers, $this->f->features);
        $this->mapId = $this->f->mapService->create(['title' => 'M', 'settings' => ['ajax' => true]], 1)->id;
        $this->layerId = $this->f->layerService->create($this->mapId, ['title' => 'L'])->id;
        $this->f->featureService->create($this->layerId, ['geometry' => ['type' => 'Point', 'coordinates' => [8.4, 49]]]);
    }

    private function publish(): void
    {
        $this->f->mapService->update($this->mapId, ['status' => 'published']);
    }

    public function testNotPublishedOrNotOnDemandMapsAreInvisible(): void
    {
        $bounds = [8.0, 48.0, 9.0, 50.0];
        self::assertNull($this->service->featuresInArea($this->mapId, $bounds, [], 1, 10), 'draft');
        self::assertNull($this->service->featuresInArea(999, $bounds, [], 1, 10), 'unknown');
        $this->publish();
        $this->f->mapService->update($this->mapId, ['settings' => ['ajax' => false]]);
        self::assertNull($this->service->featuresInArea($this->mapId, $bounds, [], 1, 10), 'inline mode');
    }

    public function testOnlyVisiblePublishedLayersAreServedAndGroupedByLayer(): void
    {
        $this->publish();
        $hidden = $this->f->layerService->create($this->mapId, ['title' => 'H', 'visible' => false])->id;
        $draft = $this->f->layerService->create($this->mapId, ['title' => 'D', 'status' => 'draft'])->id;
        foreach ([$hidden, $draft] as $id) {
            $this->f->featureService->create($id, ['geometry' => ['type' => 'Point', 'coordinates' => [8.4, 49]]]);
        }
        $result = $this->service->featuresInArea($this->mapId, [8.0, 48.0, 9.0, 50.0], [], 1, 10);
        self::assertSame([$this->layerId], array_keys($result['layers'] ?? []));
        self::assertSame(1, $result['total'] ?? null);
        self::assertSame([$this->layerId], array_map(fn ($l): int => $l->id, $this->service->visibleLayers($this->mapId)));
        self::assertCount(2, $this->service->visibleLayers($this->mapId, true));
    }

    public function testPageSizeIsBounded(): void
    {
        $this->publish();
        for ($i = 0; $i < 3; ++$i) {
            $this->f->featureService->create($this->layerId, ['geometry' => ['type' => 'Point', 'coordinates' => [8.4 + $i / 100, 49]]]);
        }
        $one = $this->service->featuresInArea($this->mapId, [8.0, 48.0, 9.0, 50.0], [], 1, 0);
        self::assertCount(1, $one['layers'][$this->layerId] ?? [], 'a page size below 1 becomes 1');
        $page2 = $this->service->featuresInArea($this->mapId, [8.0, 48.0, 9.0, 50.0], [], 2, 2);
        self::assertSame([2, 4], [count($page2['layers'][$this->layerId] ?? []), $page2['total'] ?? null]);
    }
}
