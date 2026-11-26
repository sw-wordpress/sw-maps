<?php

declare(strict_types=1);

namespace SWMaps\Core\Domain;

use SWMaps\Core\Geo\Bounds;

final class Feature
{
    /** @param array<string, mixed> $style */
    public function __construct(
        public readonly int $id,
        public readonly int $layerId,
        public readonly ?string $externalId,
        public readonly Geometry $geometry,
        public readonly FeatureProperties $properties,
        public readonly array $style,
        public readonly \DateTimeImmutable $updatedAt
    ) {
    }

    public function bounds(): Bounds
    {
        return $this->geometry->bounds();
    }

    /** @param array<string, mixed>|null $style */
    public function with(
        ?Geometry $geometry = null,
        ?FeatureProperties $properties = null,
        ?array $style = null,
        ?\DateTimeImmutable $updatedAt = null
    ): self {
        return new self($this->id, $this->layerId, $this->externalId, $geometry ?? $this->geometry, $properties ?? $this->properties, $style ?? $this->style, $updatedAt ?? $this->updatedAt);
    }

    public function withId(int $id): self
    {
        return new self($id, $this->layerId, $this->externalId, $this->geometry, $this->properties, $this->style, $this->updatedAt);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'layer_id' => $this->layerId,
            'external_id' => $this->externalId,
            'geometry' => $this->geometry->toArray(),
            'properties' => $this->properties->toArray(),
            'style' => $this->style === [] ? new \stdClass() : $this->style,
            'updated_at' => $this->updatedAt->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
