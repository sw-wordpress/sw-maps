<?php

declare(strict_types=1);

namespace SWMaps\Tests\Support;

use SWMaps\Core\Contracts\CategoryRepositoryInterface;
use SWMaps\Core\Domain\Category;

final class InMemoryCategories implements CategoryRepositoryInterface
{
    /** @var array<int, Category> */
    public array $items = [];
    private int $seq = 0;

    public function __construct(private readonly InMemoryFeatures $features)
    {
    }

    public function find(int $id): ?Category
    {
        return $this->items[$id] ?? null;
    }

    public function all(): array
    {
        $all = array_values($this->items);
        usort($all, static fn (Category $a, Category $b): int => strcmp($a->title, $b->title));

        return $all;
    }

    public function existing(array $ids): array
    {
        return array_values(array_filter($ids, fn (int $id): bool => isset($this->items[$id])));
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        foreach ($this->items as $c) {
            if ($c->slug === $slug && $c->id !== $exceptId) {
                return true;
            }
        }

        return false;
    }

    public function save(Category $category): Category
    {
        $saved = $category->id === 0 ? $category->withId(++$this->seq) : $category;
        $this->items[$saved->id] = $saved;

        return $saved;
    }

    public function delete(int $id): void
    {
        unset($this->items[$id]);
        foreach ($this->features->items as $feature) {
            if (in_array($id, $feature->categoryIds, true)) {
                $this->features->items[$feature->id] = $feature->with(null, null, null, null, array_values(array_diff($feature->categoryIds, [$id])));
            }
        }
    }
}
