<?php

declare(strict_types=1);

namespace SWMaps\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SWMaps\Core\Exception\ConflictException;
use SWMaps\Core\Exception\NotFoundException;
use SWMaps\Core\Exception\ValidationException;
use SWMaps\Tests\Support\Fixture;

final class CategoryServiceTest extends TestCase
{
    private Fixture $f;

    protected function setUp(): void
    {
        $this->f = new Fixture();
    }

    public function testCreateGeneratesUniqueSlugsAndValidatesStyle(): void
    {
        $a = $this->f->categoryService->create(['title' => 'Hütten', 'style' => ['color' => '#00aa00', 'icon' => 'builtin:home']]);
        $b = $this->f->categoryService->create(['title' => 'Hütten']);
        $c = $this->f->categoryService->create(['title' => '???']);
        self::assertSame(['huetten', 'huetten-2', 'category'], [$a->slug, $b->slug, $c->slug]);
        self::assertSame('builtin:home', $a->style['icon']);
        self::assertSame(['id' => $a->id, 'slug' => 'huetten', 'title' => 'Hütten', 'style' => ['color' => '#00aa00', 'icon' => 'builtin:home']], $a->toArray());
        self::assertInstanceOf(\stdClass::class, $b->toArray()['style']);
        self::assertCount(3, $this->f->categoryService->all());
        $this->expectException(ValidationException::class);
        $this->f->categoryService->create(['title' => 'X', 'style' => ['icon' => 'builtin:nope']]);
    }

    public function testTitleIsRequired(): void
    {
        $this->expectException(ValidationException::class);
        $this->f->categoryService->create([]);
    }

    public function testUpdateAndSlugRules(): void
    {
        $a = $this->f->categoryService->create(['title' => 'A']);
        $b = $this->f->categoryService->create(['title' => 'B']);
        $u = $this->f->categoryService->update($a->id, ['title' => 'A2', 'slug' => 'alpha', 'style' => ['color' => '#112233']]);
        self::assertSame(['A2', 'alpha', '#112233'], [$u->title, $u->slug, $u->style['color']]);
        self::assertSame('alpha', $this->f->categoryService->update($a->id, ['title' => 'A3'])->slug);
        foreach ([['slug' => 'Bad Slug'], ['title' => '']] as $patch) {
            try {
                $this->f->categoryService->update($a->id, $patch);
                self::fail('expected ValidationException');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(ConflictException::class);
        $this->f->categoryService->update($b->id, ['slug' => 'alpha']);
    }

    public function testDeleteRemovesAssignmentsAndUnknownIsNotFound(): void
    {
        $map = $this->f->mapService->create(['title' => 'M'], 1)->id;
        $layer = $this->f->layerService->create($map, ['title' => 'L']);
        $cat = $this->f->categoryService->create(['title' => 'C']);
        $feature = $this->f->featureService->create($layer->id, ['geometry' => ['type' => 'Point', 'coordinates' => [1, 1]], 'categories' => [$cat->id]]);
        self::assertSame([$cat->id], $feature->categoryIds);
        $this->f->categoryService->delete($cat->id);
        self::assertSame([], $this->f->featureService->get($feature->id)->categoryIds);
        $this->expectException(NotFoundException::class);
        $this->f->categoryService->delete($cat->id);
    }

    public function testFeatureCategoryAssignmentRules(): void
    {
        $map = $this->f->mapService->create(['title' => 'M'], 1)->id;
        $layer = $this->f->layerService->create($map, ['title' => 'L']);
        $a = $this->f->categoryService->create(['title' => 'A']);
        $b = $this->f->categoryService->create(['title' => 'B']);
        $feature = $this->f->featureService->create($layer->id, ['geometry' => ['type' => 'Point', 'coordinates' => [1, 1]], 'categories' => [$b->id, $a->id, $a->id]]);
        self::assertSame([$a->id, $b->id], $feature->categoryIds);
        self::assertSame([$a->id, $b->id], $this->f->featureService->update($feature->id, ['properties' => ['title' => 'x']])->categoryIds, 'absent key keeps assignments');
        self::assertSame([], $this->f->featureService->update($feature->id, ['categories' => []])->categoryIds);
        self::assertSame([$a->id, $b->id], $feature->toArray()['categories']);
        foreach ([[999], 'x', ['a'], [0], range(1, 51)] as $bad) {
            try {
                $this->f->featureService->update($feature->id, ['categories' => $bad]);
                self::fail('expected ValidationException');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('categories', $e->errors());
            }
        }
    }
}
