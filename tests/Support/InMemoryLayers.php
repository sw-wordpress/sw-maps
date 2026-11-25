<?php

declare(strict_types=1);

namespace SWMaps\Tests\Support;

use SWMaps\Core\Contracts\LayerRepositoryInterface;
use SWMaps\Core\Domain\Layer;

final class InMemoryLayers implements LayerRepositoryInterface
{
    /** @var array<int, Layer> */
    public array $items = [];
    private int $seq = 0;

    public function find(int $id): ?Layer
    {
        return $this->items[$id] ?? null;
    }

    public function forMap(int $mapId): array
    {
        $list = array_values(array_filter($this->items, static fn (Layer $l): bool => $l->mapId === $mapId));
        usort($list, static fn (Layer $a, Layer $b): int => [$a->sortOrder, $a->id] <=> [$b->sortOrder, $b->id]);

        return $list;
    }

    public function save(Layer $layer): Layer
    {
        $saved = $layer->id === 0 ? $layer->withId(++$this->seq) : $layer;
        $this->items[$saved->id] = $saved;

        return $saved;
    }

    public function delete(int $id): void
    {
        unset($this->items[$id]);
    }

    public function deleteForMap(int $mapId): void
    {
        foreach ($this->forMap($mapId) as $l) {
            unset($this->items[$l->id]);
        }
    }

    public function nextSortOrder(int $mapId): int
    {
        $max = -1;
        foreach ($this->forMap($mapId) as $l) {
            $max = max($max, $l->sortOrder);
        }

        return $max + 1;
    }
}
