<?php

declare(strict_types=1);

namespace SWMaps\Core\Contracts;

use SWMaps\Core\Domain\Layer;

interface LayerRepositoryInterface
{
    public function find(int $id): ?Layer;

    /** @return list<Layer> ordered by sort order, then id */
    public function forMap(int $mapId): array;

    public function save(Layer $layer): Layer;

    public function delete(int $id): void;

    public function deleteForMap(int $mapId): void;

    public function nextSortOrder(int $mapId): int;
}
