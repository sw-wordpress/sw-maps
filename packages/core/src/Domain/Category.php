<?php

declare(strict_types=1);

namespace SWMaps\Core\Domain;

/**
 * Site-wide category for locations. Its style (colour, icon) is the default for the locations in it, so categories
 * can be told apart by icon as well as by colour.
 */
final class Category
{
    /** @param array<string, mixed> $style */
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $title,
        public readonly array $style
    ) {
    }

    public function withId(int $id): self
    {
        return new self($id, $this->slug, $this->title, $this->style);
    }

    /** @param array<string, mixed>|null $style */
    public function with(?string $slug = null, ?string $title = null, ?array $style = null): self
    {
        return new self($this->id, $slug ?? $this->slug, $title ?? $this->title, $style ?? $this->style);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'slug' => $this->slug, 'title' => $this->title, 'style' => $this->style === [] ? new \stdClass() : $this->style];
    }
}
