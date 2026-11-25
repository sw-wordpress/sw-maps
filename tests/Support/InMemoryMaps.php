<?php

declare(strict_types=1);

namespace SWMaps\Tests\Support;

use SWMaps\Core\Contracts\MapRepositoryInterface;
use SWMaps\Core\Domain\MapEntity;

final class InMemoryMaps implements MapRepositoryInterface
{
    /** @var array<int, MapEntity> */
    public array $items = [];
    private int $seq = 0;

    public function find(int $id): ?MapEntity
    {
        return $this->items[$id] ?? null;
    }

    public function findBySlug(string $slug): ?MapEntity
    {
        foreach ($this->items as $m) {
            if ($m->slug === $slug) {
                return $m;
            }
        }

        return null;
    }

    public function search(string $search, ?string $status, int $page, int $perPage): array
    {
        $all = array_values(array_filter(
            $this->items,
            static fn (MapEntity $m): bool => ($search === '' || stripos($m->title, $search) !== false) && ($status === null || $m->status->value === $status)
        ));

        return ['items' => array_slice($all, ($page - 1) * $perPage, $perPage), 'total' => count($all)];
    }

    public function save(MapEntity $map): MapEntity
    {
        $saved = $map->id === 0 ? $map->withId(++$this->seq) : $map;
        $this->items[$saved->id] = $saved;

        return $saved;
    }

    public function delete(int $id): void
    {
        unset($this->items[$id]);
    }

    public function slugExists(string $slug, int $exceptId = 0): bool
    {
        foreach ($this->items as $m) {
            if ($m->slug === $slug && $m->id !== $exceptId) {
                return true;
            }
        }

        return false;
    }
}
