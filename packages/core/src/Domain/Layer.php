<?php

declare(strict_types=1);

namespace SWMaps\Core\Domain;

/** A layer belongs to exactly one map. Only `published` layers of published maps are public. */
final class Layer
{
    /** @param array<string, mixed> $style */
    public function __construct(
        public readonly int $id,
        public readonly int $mapId,
        public readonly string $title,
        public readonly LayerType $type,
        public readonly MapStatus $status,
        public readonly int $sortOrder,
        public readonly bool $visible,
        public readonly array $style,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt
    ) {
    }

    public function isPublished(): bool
    {
        return $this->status === MapStatus::Published;
    }

    /** @param array<string, mixed>|null $style */
    public function with(
        ?string $title = null,
        ?MapStatus $status = null,
        ?int $sortOrder = null,
        ?bool $visible = null,
        ?array $style = null,
        ?\DateTimeImmutable $updatedAt = null
    ): self {
        return new self(
            $this->id,
            $this->mapId,
            $title ?? $this->title,
            $this->type,
            $status ?? $this->status,
            $sortOrder ?? $this->sortOrder,
            $visible ?? $this->visible,
            $style ?? $this->style,
            $this->createdAt,
            $updatedAt ?? $this->updatedAt
        );
    }

    public function withId(int $id, ?int $mapId = null): self
    {
        return new self($id, $mapId ?? $this->mapId, $this->title, $this->type, $this->status, $this->sortOrder, $this->visible, $this->style, $this->createdAt, $this->updatedAt);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'map_id' => $this->mapId,
            'title' => $this->title,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'sort_order' => $this->sortOrder,
            'visible' => $this->visible,
            'style' => $this->style === [] ? new \stdClass() : $this->style,
            'created_at' => $this->createdAt->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $this->updatedAt->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
