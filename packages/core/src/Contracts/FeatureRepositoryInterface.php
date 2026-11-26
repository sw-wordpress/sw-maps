<?php

declare(strict_types=1);

namespace SWMaps\Core\Contracts;

use SWMaps\Core\Domain\Feature;

interface FeatureRepositoryInterface
{
    public function find(int $id): ?Feature;

    /** @return list<Feature> ordered by id */
    public function page(int $layerId, int $page, int $perPage): array;

    /**
     * @param list<int> $layerIds
     * @return list<Feature> all features of the layers (used for inline rendering)
     */
    public function forLayers(array $layerIds): array;

    /**
     * @param list<string> $externalIds
     * @return array<string, Feature> existing features of the layer keyed by external id
     */
    public function byExternalIds(int $layerId, array $externalIds): array;

    /**
     * Features whose bounding box intersects the bounds (used by the optional AJAX mode).
     *
     * @param list<int> $layerIds
     * @param array{0: float, 1: float, 2: float, 3: float} $bounds minLon, minLat, maxLon, maxLat
     * @param list<int> $categoryIds when not empty, only features in at least one of these categories
     * @return array{items: list<Feature>, total: int}
     */
    public function inBounds(array $layerIds, array $bounds, array $categoryIds, int $page, int $perPage): array;

    public function count(int $layerId): int;

    public function countForMap(int $mapId): int;

    public function save(Feature $feature): Feature;

    public function delete(int $id): void;

    public function deleteForLayer(int $layerId): void;

    public function deleteForMap(int $mapId): void;
}
