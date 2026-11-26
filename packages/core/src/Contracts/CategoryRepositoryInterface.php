<?php

declare(strict_types=1);

namespace SWMaps\Core\Contracts;

use SWMaps\Core\Domain\Category;

interface CategoryRepositoryInterface
{
    public function find(int $id): ?Category;

    /** @return list<Category> ordered by title */
    public function all(): array;

    /**
     * @param list<int> $ids
     * @return list<int> the ids that exist
     */
    public function existing(array $ids): array;

    public function slugExists(string $slug, ?int $exceptId = null): bool;

    public function save(Category $category): Category;

    /** Deletes the category and its assignments. */
    public function delete(int $id): void;
}
