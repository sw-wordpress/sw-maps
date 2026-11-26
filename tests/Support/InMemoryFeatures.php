<?php

declare(strict_types=1);

namespace SWMaps\Tests\Support;

use SWMaps\Core\Contracts\FeatureRepositoryInterface;
use SWMaps\Core\Domain\Feature;

final class InMemoryFeatures implements FeatureRepositoryInterface
{
    /** @var array<int, Feature> */
    public array $items = [];
    private int $seq = 0;

    public function __construct(private readonly InMemoryLayers $layers)
    {
    }

    public function find(int $id): ?Feature
    {
        return $this->items[$id] ?? null;
    }

    public function page(int $layerId, int $page, int $perPage): array
    {
        return array_slice($this->forLayers([$layerId]), ($page - 1) * $perPage, $perPage);
    }

    public function forLayers(array $layerIds): array
    {
        return array_values(array_filter($this->items, static fn (Feature $f): bool => in_array($f->layerId, $layerIds, true)));
    }

    public function byExternalIds(int $layerId, array $externalIds): array
    {
        $out = [];
        foreach ($this->forLayers([$layerId]) as $f) {
            if ($f->externalId !== null && in_array($f->externalId, $externalIds, true)) {
                $out[$f->externalId] = $f;
            }
        }

        return $out;
    }

    public function inBounds(array $layerIds, array $bounds, array $categoryIds, int $page, int $perPage): array
    {
        $matching = array_values(array_filter($this->forLayers($layerIds), static function (Feature $f) use ($bounds, $categoryIds): bool {
            $b = $f->bounds();

            $lon = $bounds[0] <= $bounds[2]
                ? $b->maxLongitude >= $bounds[0] && $b->minLongitude <= $bounds[2]
                : $b->maxLongitude >= $bounds[0] || $b->minLongitude <= $bounds[2];

            return $lon && $b->maxLatitude >= $bounds[1] && $b->minLatitude <= $bounds[3]
                && ($categoryIds === [] || array_intersect($categoryIds, $f->categoryIds) !== []);
        }));

        return ['items' => array_slice($matching, ($page - 1) * $perPage, $perPage), 'total' => count($matching)];
    }

    public function count(int $layerId): int
    {
        return count($this->forLayers([$layerId]));
    }

    public function countForMap(int $mapId): int
    {
        $ids = array_map(static fn ($l): int => $l->id, $this->layers->forMap($mapId));

        return count($this->forLayers($ids));
    }

    public function save(Feature $feature): Feature
    {
        $saved = $feature->id === 0 ? $feature->withId(++$this->seq) : $feature;
        $this->items[$saved->id] = $saved;

        return $saved;
    }

    public function delete(int $id): void
    {
        unset($this->items[$id]);
    }

    public function deleteForLayer(int $layerId): void
    {
        foreach ($this->forLayers([$layerId]) as $f) {
            unset($this->items[$f->id]);
        }
    }

    public function deleteForMap(int $mapId): void
    {
        foreach ($this->layers->forMap($mapId) as $l) {
            $this->deleteForLayer($l->id);
        }
    }
}
