<?php

declare(strict_types=1);

namespace SWMaps\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SWMaps\Core\Application\Slug;
use SWMaps\Core\Domain\MapStatus;
use SWMaps\Core\Exception\ConflictException;
use SWMaps\Core\Exception\NotFoundException;
use SWMaps\Core\Exception\ValidationException;
use SWMaps\Tests\Support\Fixture;

final class MapServiceTest extends TestCase
{
    private Fixture $f;

    protected function setUp(): void
    {
        $this->f = new Fixture();
    }

    public function testCreateGeneratesUniqueSlugAndStartsAsDraft(): void
    {
        $a = $this->f->mapService->create(['title' => 'Rathaus Süd'], 5);
        $b = $this->f->mapService->create(['title' => 'Rathaus Süd'], 5);
        self::assertSame('rathaus-sued', $a->slug);
        self::assertSame('rathaus-sued-2', $b->slug);
        self::assertSame(MapStatus::Draft, $a->status);
        self::assertSame(5, $a->createdBy);
    }

    public function testCreateRequiresTitle(): void
    {
        $this->expectException(ValidationException::class);
        $this->f->mapService->create(['title' => '  '], 1);
    }

    public function testSlugFromPunctuationFallsBackToMap(): void
    {
        self::assertSame('map', $this->f->mapService->create(['title' => '!!!', 'slug' => '???'], 1)->slug);
        self::assertTrue(Slug::valid('a-b-1'));
        self::assertFalse(Slug::valid('A_b'));
    }

    public function testGetUnknownThrows(): void
    {
        $this->expectException(NotFoundException::class);
        $this->f->mapService->get(99);
    }

    public function testUpdateAndPublishFiresEventOnce(): void
    {
        $m = $this->f->mapService->create(['title' => 'A'], 1);
        $r = $this->f->mapService->update($m->id, ['title' => 'B', 'status' => 'published', 'viewport' => ['zoom' => 9], 'settings' => ['height' => 500]]);
        self::assertTrue($r['published']);
        self::assertSame(['B', 9.0, 500], [$r['map']->title, $r['map']->viewport->zoom, $r['map']->settings->height]);
        $again = $this->f->mapService->update($m->id, ['status' => 'published']);
        self::assertFalse($again['published']);
        self::assertSame(['published:' . $m->id], $this->f->events->log);
    }

    public function testUpdateValidation(): void
    {
        $m = $this->f->mapService->create(['title' => 'A'], 1);
        $other = $this->f->mapService->create(['title' => 'B'], 1);
        foreach ([['status' => 'archived'], ['status' => 3], ['slug' => 'Bad Slug'], ['title' => ''], ['viewport' => 'x']] as $patch) {
            try {
                $this->f->mapService->update($m->id, $patch);
                self::fail('accepted ' . json_encode($patch));
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(ConflictException::class);
        $this->f->mapService->update($m->id, ['slug' => $other->slug]);
    }

    public function testListFiltersAndValidatesStatus(): void
    {
        $this->f->mapService->create(['title' => 'Alpha'], 1);
        $b = $this->f->mapService->create(['title' => 'Beta'], 1);
        $this->f->mapService->update($b->id, ['status' => 'published']);
        self::assertSame(1, $this->f->mapService->list('alp', null, 1, 20)['total']);
        self::assertSame(1, $this->f->mapService->list('', 'published', 1, 20)['total']);
        $this->expectException(ValidationException::class);
        $this->f->mapService->list('', 'nope', 1, 20);
    }

    public function testDeleteCascadesInsideTransaction(): void
    {
        $m = $this->f->mapService->create(['title' => 'A'], 1);
        $l = $this->f->layerService->create($m->id, ['title' => 'L']);
        $this->f->featureService->create($l->id, ['geometry' => ['type' => 'Point', 'coordinates' => [1, 2]]]);
        $this->f->mapService->delete($m->id);
        self::assertSame([[], [], []], [$this->f->maps->items, $this->f->layers->items, $this->f->features->items]);
        self::assertSame(1, $this->f->tx->runs);
    }

    public function testDuplicateCopiesLayersAndFeaturesAsDraft(): void
    {
        $m = $this->f->mapService->create(['title' => 'Orig'], 1);
        $this->f->mapService->update($m->id, ['status' => 'published']);
        $l = $this->f->layerService->create($m->id, ['title' => 'L']);
        $this->f->featureService->create($l->id, ['geometry' => ['type' => 'Point', 'coordinates' => [1, 2]], 'properties' => ['title' => 'P'], 'external_id' => 'x']);
        $copy = $this->f->mapService->duplicate($m->id, 9);
        self::assertSame(MapStatus::Draft, $copy->status);
        self::assertSame(9, $copy->createdBy);
        self::assertSame('Orig (copy)', $copy->title);
        self::assertSame(['layers' => 1, 'features' => 1], $this->f->mapService->counts($copy->id));
        self::assertSame(['layers' => 1, 'features' => 1], $this->f->mapService->counts($m->id));
    }
}
