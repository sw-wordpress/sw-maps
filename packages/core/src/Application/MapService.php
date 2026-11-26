<?php

declare(strict_types=1);

namespace SWMaps\Core\Application;

use SWMaps\Core\Contracts\ClockInterface;
use SWMaps\Core\Contracts\EventsInterface;
use SWMaps\Core\Contracts\FeatureRepositoryInterface;
use SWMaps\Core\Contracts\LayerRepositoryInterface;
use SWMaps\Core\Contracts\MapRepositoryInterface;
use SWMaps\Core\Contracts\TransactionInterface;
use SWMaps\Core\Domain\Feature;
use SWMaps\Core\Domain\Layer;
use SWMaps\Core\Domain\MapEntity;
use SWMaps\Core\Domain\MapSettings;
use SWMaps\Core\Domain\MapStatus;
use SWMaps\Core\Domain\Viewport;
use SWMaps\Core\Exception\ConflictException;
use SWMaps\Core\Exception\NotFoundException;
use SWMaps\Core\Exception\ValidationException;

final class MapService
{
    private const SLUG_RETRIES = 5;

    public function __construct(
        private readonly MapRepositoryInterface $maps,
        private readonly LayerRepositoryInterface $layers,
        private readonly FeatureRepositoryInterface $features,
        private readonly TransactionInterface $tx,
        private readonly ClockInterface $clock,
        private readonly EventsInterface $events
    ) {
    }

    /** @return array{items: list<MapEntity>, total: int} */
    public function list(string $search, ?string $status, int $page, int $perPage): array
    {
        if ($status !== null && MapStatus::tryFrom($status) === null) {
            throw ValidationException::field('status', 'Unknown status.');
        }

        return $this->maps->search($search, $status, max(1, $page), min(100, max(1, $perPage)));
    }

    public function get(int $id): MapEntity
    {
        return $this->maps->find($id) ?? throw NotFoundException::for('Map', $id);
    }

    /** @param array<string, mixed> $input */
    public function create(array $input, int $userId): MapEntity
    {
        $title = Input::string($input, 'title', true, 200) ?? '';
        $now = $this->clock->now();
        $base = Input::string($input, 'slug', false, 100) ?: Slug::from($title);
        $viewport = Viewport::fromArray(Input::object($input, 'viewport') ?? []);
        $settings = MapSettings::fromArray(Input::object($input, 'settings') ?? []);
        $description = Input::string($input, 'description', false, 2000) ?? '';

        // Two requests can pick the same free slug at the same time; the unique index decides and the loser retries.
        for ($attempt = 0;; ++$attempt) {
            $slug = $this->uniqueSlug($base, $attempt);
            $map = new MapEntity(0, $slug, $title, $description, MapStatus::Draft, $viewport, $settings, $userId, $now, $now);
            try {
                return $this->maps->save($map);
            } catch (ConflictException $e) {
                if ($attempt >= self::SLUG_RETRIES) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $patch
     * @return array{map: MapEntity, published: bool}
     */
    public function update(int $id, array $patch): array
    {
        $map = $this->get($id);
        $status = $map->status;
        if (array_key_exists('status', $patch)) {
            $status = is_string($patch['status']) ? MapStatus::tryFrom($patch['status']) : null;
            $status ?? throw ValidationException::field('status', 'Unknown status.');
        }
        $slug = Input::string($patch, 'slug', false, 100);
        if ($slug !== null) {
            if (!Slug::valid($slug)) {
                throw ValidationException::field('slug', 'Use lower case letters, digits and hyphens.');
            }
            if ($this->maps->slugExists($slug, $id)) {
                throw new ConflictException('Slug already in use.');
            }
        }
        $viewportPatch = Input::object($patch, 'viewport');
        $settingsPatch = Input::object($patch, 'settings');
        $updated = $map->with(
            $slug,
            Input::string($patch, 'title', false, 200),
            Input::string($patch, 'description', false, 2000),
            $status,
            $viewportPatch === null ? null : Viewport::fromArray($viewportPatch, $map->viewport),
            $settingsPatch === null ? null : MapSettings::fromArray($settingsPatch, $map->settings),
            $this->clock->now()
        );
        if ($updated->title === '') {
            throw ValidationException::field('title', 'Must not be empty.');
        }
        $saved = $this->maps->save($updated);
        $published = $status === MapStatus::Published && !$map->isPublished();
        if ($published) {
            $this->events->mapPublished($id);
        }

        return ['map' => $saved, 'published' => $published];
    }

    public function delete(int $id): void
    {
        $this->get($id);
        $this->tx->run(function () use ($id): void {
            $this->features->deleteForMap($id);
            $this->layers->deleteForMap($id);
            $this->maps->delete($id);
        });
    }

    public function duplicate(int $id, int $userId): MapEntity
    {
        $source = $this->get($id);
        $now = $this->clock->now();

        return $this->tx->run(function () use ($source, $userId, $now): MapEntity {
            $copy = $this->maps->save(new MapEntity(
                0,
                $this->uniqueSlug($source->slug . '-copy'),
                $source->title . ' (copy)',
                $source->description,
                MapStatus::Draft,
                $source->viewport,
                $source->settings,
                $userId,
                $now,
                $now
            ));
            foreach ($this->layers->forMap($source->id) as $layer) {
                $newLayer = $this->layers->save(new Layer(0, $copy->id, $layer->title, $layer->type, $layer->status, $layer->sortOrder, $layer->visible, $layer->style, $now, $now));
                foreach ($this->features->forLayers([$layer->id]) as $feature) {
                    $this->features->save(new Feature(0, $newLayer->id, $feature->externalId, $feature->geometry, $feature->properties, $feature->style, $now));
                }
            }

            return $copy;
        });
    }

    /** @return array{layers: int, features: int} */
    public function counts(int $mapId): array
    {
        return ['layers' => count($this->layers->forMap($mapId)), 'features' => $this->features->countForMap($mapId)];
    }

    /** @param int $skip number of candidate suffixes to skip (after a lost race on the unique index) */
    private function uniqueSlug(string $base, int $skip = 0): string
    {
        $base = Slug::valid($base) ? $base : (Slug::from($base) ?: 'map');
        $slug = $skip === 0 ? $base : substr($base, 0, 90) . '-' . bin2hex(random_bytes(2));
        for ($i = 2; $this->maps->slugExists($slug); ++$i) {
            $slug = substr($base, 0, 90) . '-' . $i;
        }

        return $slug;
    }
}
