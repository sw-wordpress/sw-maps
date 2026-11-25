<?php

declare(strict_types=1);

namespace SWMaps\Core\Application;

use SWMaps\Core\Contracts\ClockInterface;
use SWMaps\Core\Contracts\EventsInterface;
use SWMaps\Core\Contracts\FeatureRepositoryInterface;
use SWMaps\Core\Contracts\LayerRepositoryInterface;
use SWMaps\Core\Domain\Feature;
use SWMaps\Core\Domain\FeatureProperties;
use SWMaps\Core\Domain\Geometry;
use SWMaps\Core\Exception\InvalidGeometryException;
use SWMaps\Core\Exception\NotFoundException;
use SWMaps\Core\Exception\ValidationException;
use SWMaps\Core\Geo\GeometryValidator;

final class FeatureService
{
    public const MAX_PER_PAGE = 500;

    public function __construct(
        private readonly LayerRepositoryInterface $layers,
        private readonly FeatureRepositoryInterface $features,
        private readonly ClockInterface $clock,
        private readonly EventsInterface $events,
        private readonly GeometryValidator $validator = new GeometryValidator()
    ) {
    }

    /** @return array{items: list<Feature>, total: int} */
    public function page(int $layerId, int $page, int $perPage): array
    {
        $this->layers->find($layerId) ?? throw NotFoundException::for('Layer', $layerId);

        return [
            'items' => $this->features->page($layerId, max(1, $page), min(self::MAX_PER_PAGE, max(1, $perPage))),
            'total' => $this->features->count($layerId),
        ];
    }

    public function get(int $id): Feature
    {
        return $this->features->find($id) ?? throw NotFoundException::for('Feature', $id);
    }

    /** @param array<string, mixed> $input */
    public function create(int $layerId, array $input): Feature
    {
        $layer = $this->layers->find($layerId) ?? throw NotFoundException::for('Layer', $layerId);
        $geometry = Input::object($input, 'geometry') ?? throw InvalidGeometryException::because('Geometry is required.');
        $properties = Input::object($input, 'properties') ?? [];
        $external = Input::string($input, 'external_id', false, 190);
        $feature = new Feature(
            0,
            $layerId,
            $external === '' ? null : $external,
            Geometry::fromArray($geometry, $this->validator),
            FeatureProperties::fromArray($properties),
            Input::style($input, 'style') ?? [],
            $this->clock->now()
        );
        $saved = $this->features->save($feature);
        $this->events->layerUpdated($layerId, $layer->mapId);

        return $saved;
    }

    /** @param array<string, mixed> $patch */
    public function update(int $id, array $patch): Feature
    {
        $feature = $this->get($id);
        $geometry = Input::object($patch, 'geometry');
        $properties = Input::object($patch, 'properties');
        $updated = $feature->with(
            $geometry === null ? null : Geometry::fromArray($geometry, $this->validator),
            $properties === null ? null : FeatureProperties::fromArray($properties, $feature->properties),
            Input::style($patch, 'style'),
            $this->clock->now()
        );
        $saved = $this->features->save($updated);
        $this->notify($feature->layerId);

        return $saved;
    }

    public function delete(int $id): void
    {
        $feature = $this->get($id);
        $this->features->delete($id);
        $this->notify($feature->layerId);
    }

    private function notify(int $layerId): void
    {
        $layer = $this->layers->find($layerId);
        if ($layer !== null) {
            $this->events->layerUpdated($layerId, $layer->mapId);
        }
    }
}
