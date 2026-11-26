<?php

declare(strict_types=1);

namespace SWMaps\Core\Domain;

/** A map. `id` 0 means not yet persisted. */
final class MapEntity
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $description,
        public readonly MapStatus $status,
        public readonly Viewport $viewport,
        public readonly MapSettings $settings,
        public readonly int $createdBy,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt
    ) {
    }

    public function isPublished(): bool
    {
        return $this->status === MapStatus::Published;
    }

    public function with(
        ?string $slug = null,
        ?string $title = null,
        ?string $description = null,
        ?MapStatus $status = null,
        ?Viewport $viewport = null,
        ?MapSettings $settings = null,
        ?\DateTimeImmutable $updatedAt = null
    ): self {
        return new self(
            $this->id,
            $slug ?? $this->slug,
            $title ?? $this->title,
            $description ?? $this->description,
            $status ?? $this->status,
            $viewport ?? $this->viewport,
            $settings ?? $this->settings,
            $this->createdBy,
            $this->createdAt,
            $updatedAt ?? $this->updatedAt
        );
    }

    public function withId(int $id): self
    {
        return new self($id, $this->slug, $this->title, $this->description, $this->status, $this->viewport, $this->settings, $this->createdBy, $this->createdAt, $this->updatedAt);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'viewport' => $this->viewport->toArray(),
            'settings' => $this->settings->toArray(),
            'created_at' => $this->createdAt->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $this->updatedAt->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
