<?php

declare(strict_types=1);

namespace SWMaps\Core\Contracts;

use SWMaps\Core\Domain\MapEntity;

interface MapRepositoryInterface
{
    public function find(int $id): ?MapEntity;

    public function findBySlug(string $slug): ?MapEntity;

    /** @return array{items: list<MapEntity>, total: int} */
    public function search(string $search, ?string $status, int $page, int $perPage): array;

    /** Inserts (id 0) or updates; returns the stored map. */
    public function save(MapEntity $map): MapEntity;

    public function delete(int $id): void;

    public function slugExists(string $slug, int $exceptId = 0): bool;
}
