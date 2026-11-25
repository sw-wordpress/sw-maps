<?php

declare(strict_types=1);

namespace SWMaps\Core\Application;

use SWMaps\Core\Contracts\ClockInterface;
use SWMaps\Core\Contracts\EventsInterface;
use SWMaps\Core\Contracts\FeatureRepositoryInterface;
use SWMaps\Core\Contracts\LayerRepositoryInterface;
use SWMaps\Core\Contracts\MapRepositoryInterface;
use SWMaps\Core\Contracts\TransactionInterface;
use SWMaps\Core\Domain\Layer;
use SWMaps\Core\Domain\LayerType;
use SWMaps\Core\Domain\MapStatus;
use SWMaps\Core\Exception\NotFoundException;
use SWMaps\Core\Exception\ValidationException;

final class LayerService
{
    public function __construct(
        private readonly MapRepositoryInterface $maps,
        private readonly LayerRepositoryInterface $layers,
        private readonly FeatureRepositoryInterface $features,
        private readonly TransactionInterface $tx,
        private readonly ClockInterface $clock,
        private readonly EventsInterface $events
    ) {
    }

    /** @return list<Layer> */
    public function forMap(int $mapId): array
    {
        $this->maps->find($mapId) ?? throw NotFoundException::for('Map', $mapId);

        return $this->layers->forMap($mapId);
    }

    public function get(int $id): Layer
    {
        return $this->layers->find($id) ?? throw NotFoundException::for('Layer', $id);
    }

    /** @param array<string, mixed> $input */
    public function create(int $mapId, array $input): Layer
    {
        $this->maps->find($mapId) ?? throw NotFoundException::for('Map', $mapId);
        $now = $this->clock->now();
        $layer = new Layer(
            0,
            $mapId,
            Input::string($input, 'title', true, 200) ?? '',
            LayerType::Features,
            self::status($input, MapStatus::Published),
            Input::int($input, 'sort_order', 0, 100000) ?? $this->layers->nextSortOrder($mapId),
            Input::bool($input, 'visible') ?? true,
            Input::style($input, 'style') ?? [],
            $now,
            $now
        );
        $saved = $this->layers->save($layer);
        $this->events->layerUpdated($saved->id, $mapId);

        return $saved;
    }

    /** @param array<string, mixed> $patch */
    public function update(int $id, array $patch): Layer
    {
        $layer = $this->get($id);
        $updated = $layer->with(
            Input::string($patch, 'title', false, 200),
            array_key_exists('status', $patch) ? self::status($patch, $layer->status) : null,
            Input::int($patch, 'sort_order', 0, 100000),
            Input::bool($patch, 'visible'),
            Input::style($patch, 'style'),
            $this->clock->now()
        );
        if ($updated->title === '') {
            throw ValidationException::field('title', 'Must not be empty.');
        }
        $saved = $this->layers->save($updated);
        $this->events->layerUpdated($id, $layer->mapId);

        return $saved;
    }

    public function delete(int $id): void
    {
        $layer = $this->get($id);
        $this->tx->run(function () use ($id): void {
            $this->features->deleteForLayer($id);
            $this->layers->delete($id);
        });
        $this->events->layerUpdated($id, $layer->mapId);
    }

    /** @param array<string, mixed> $data */
    private static function status(array $data, MapStatus $default): MapStatus
    {
        if (!array_key_exists('status', $data)) {
            return $default;
        }

        return is_string($data['status']) ? (MapStatus::tryFrom($data['status']) ?? throw ValidationException::field('status', 'Unknown status.'))
            : throw ValidationException::field('status', 'Unknown status.');
    }
}
