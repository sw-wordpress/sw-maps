<?php

declare(strict_types=1);

namespace SWMaps\Core\Application;

use SWMaps\Core\Contracts\FeatureRepositoryInterface;
use SWMaps\Core\Contracts\LayerRepositoryInterface;
use SWMaps\Core\Contracts\MapRepositoryInterface;
use SWMaps\Core\Domain\Feature;
use SWMaps\Core\Domain\Layer;
use SWMaps\Core\Domain\MapStatus;

/** The single place that decides what visitors may see: published maps, visible and published layers. */
final class PublicFeatureService
{
    public const MAX_PER_PAGE = 500;

    public function __construct(
        private readonly MapRepositoryInterface $maps,
        private readonly LayerRepositoryInterface $layers,
        private readonly FeatureRepositoryInterface $features
    ) {
    }

    /**
     * Layers shown to visitors (or, for a draft preview, also unpublished layers).
     *
     * @return list<Layer>
     */
    public function visibleLayers(int $mapId, bool $includeDrafts = false): array
    {
        return array_values(array_filter(
            $this->layers->forMap($mapId),
            static fn (Layer $layer): bool => $layer->visible && ($layer->isPublished() || $includeDrafts)
        ));
    }

    /**
     * Features of the visible area of an AJAX map, grouped by layer id. Null when the map does not exist, is not
     * published or does not use the on-demand mode (indistinguishable for the caller).
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $bounds minLon, minLat, maxLon, maxLat (minLon > maxLon crosses the antimeridian)
     * @param list<int> $categoryIds
     * @return array{layers: array<int, list<Feature>>, total: int}|null
     */
    public function featuresInArea(int $mapId, array $bounds, array $categoryIds, int $page, int $perPage): ?array
    {
        $map = $this->maps->find($mapId);
        if ($map === null || $map->status !== MapStatus::Published || !$map->settings->ajax) {
            return null;
        }
        $layerIds = array_map(static fn (Layer $l): int => $l->id, $this->visibleLayers($mapId));
        $result = $this->features->inBounds($layerIds, $bounds, array_slice($categoryIds, 0, 50), max(1, $page), min(self::MAX_PER_PAGE, max(1, $perPage)));
        $grouped = [];
        foreach ($result['items'] as $feature) {
            $grouped[$feature->layerId][] = $feature;
        }

        return ['layers' => $grouped, 'total' => $result['total']];
    }
}
