<?php

declare(strict_types=1);

namespace SWMaps\Core\Domain;

use SWMaps\Core\Geo\Bounds;

final class Feature
{
    /**
     * @param array<string, mixed> $style
     * @param list<int> $categoryIds
     */
    public function __construct(
        public readonly int $id,
        public readonly int $layerId,
        public readonly ?string $externalId,
        public readonly Geometry $geometry,
        public readonly FeatureProperties $properties,
        public readonly array $style,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly array $categoryIds = []
    ) {
    }

    public function bounds(): Bounds
    {
        return $this->geometry->bounds();
    }

    /**
     * @param array<string, mixed>|null $style
     * @param list<int>|null $categoryIds
     */
    public function with(
        ?Geometry $geometry = null,
        ?FeatureProperties $properties = null,
        ?array $style = null,
        ?\DateTimeImmutable $updatedAt = null,
        ?array $categoryIds = null
    ): self {
        return new self($this->id, $this->layerId, $this->externalId, $geometry ?? $this->geometry, $properties ?? $this->properties, $style ?? $this->style, $updatedAt ?? $this->updatedAt, $categoryIds ?? $this->categoryIds);
    }

    public function withId(int $id): self
    {
        return new self($id, $this->layerId, $this->externalId, $this->geometry, $this->properties, $this->style, $this->updatedAt, $this->categoryIds);
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
            'categories' => $this->categoryIds,
            'length_m' => round((new \SWMaps\Core\Geo\DistanceCalculator())->lengthOf($this->geometry), 1),
            'updated_at' => $this->updatedAt->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
