<?php

declare(strict_types=1);

namespace SWMaps\Core\Application;

use SWMaps\Core\Contracts\CategoryRepositoryInterface;
use SWMaps\Core\Contracts\TransactionInterface;
use SWMaps\Core\Domain\Category;
use SWMaps\Core\Domain\FeatureProperties;
use SWMaps\Core\Exception\ConflictException;
use SWMaps\Core\Exception\NotFoundException;
use SWMaps\Core\Exception\ValidationException;

final class CategoryService
{
    public function __construct(private readonly CategoryRepositoryInterface $categories, private readonly TransactionInterface $tx)
    {
    }

    /** @return list<Category> */
    public function all(): array
    {
        return $this->categories->all();
    }

    public function get(int $id): Category
    {
        return $this->categories->find($id) ?? throw NotFoundException::for('Category', $id);
    }

    /** @param array<string, mixed> $input */
    public function create(array $input): Category
    {
        $title = Input::string($input, 'title', true, 200) ?? '';
        $style = $this->style($input) ?? [];
        $base = Input::string($input, 'slug', false, 100) ?: Slug::from($title);
        if ($base === '' || !Slug::valid($base)) {
            $base = 'category';
        }
        for ($attempt = 0;; ++$attempt) {
            $slug = $attempt === 0 ? $base : substr($base, 0, 90) . '-' . ($attempt + 1);
            if ($this->categories->slugExists($slug)) {
                continue;
            }
            try {
                return $this->categories->save(new Category(0, $slug, $title, $style));
            } catch (ConflictException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }

    /** @param array<string, mixed> $patch */
    public function update(int $id, array $patch): Category
    {
        $category = $this->get($id);
        $slug = Input::string($patch, 'slug', false, 100);
        if ($slug !== null) {
            if (!Slug::valid($slug)) {
                throw ValidationException::field('slug', 'Use lower case letters, digits and hyphens.');
            }
            if ($this->categories->slugExists($slug, $id)) {
                throw new ConflictException('Slug already in use.');
            }
        }
        $title = Input::string($patch, 'title', false, 200);
        if ($title === '') {
            throw ValidationException::field('title', 'Must not be empty.');
        }

        return $this->categories->save($category->with($slug, $title, $this->style($patch)));
    }

    public function delete(int $id): void
    {
        $this->get($id);
        $this->tx->run(fn () => $this->categories->delete($id));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, scalar|null>|null
     */
    private function style(array $data): ?array
    {
        $style = Input::style($data, 'style');
        if ($style !== null && isset($style['icon']) && (!is_string($style['icon']) || !FeatureProperties::validIcon($style['icon']))) {
            throw ValidationException::field('style', 'Unknown icon.');
        }

        return $style;
    }
}
