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

    public function count(int $layerId): int;

    public function countForMap(int $mapId): int;

    public function save(Feature $feature): Feature;

    public function delete(int $id): void;

    public function deleteForLayer(int $layerId): void;

    public function deleteForMap(int $mapId): void;
}
