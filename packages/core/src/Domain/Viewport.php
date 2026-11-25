<?php

declare(strict_types=1);

namespace SWMaps\Core\Domain;

use SWMaps\Core\Exception\ValidationException;
use SWMaps\Core\Geo\Bounds;
use SWMaps\Core\Geo\Coordinate;

/** Initial view of a map: centre, zoom, zoom limits, optional geographic limit and automatic bounds. */
final class Viewport
{
    public const MAX_ZOOM = 22;

    public function __construct(
        public readonly Coordinate $center,
        public readonly float $zoom,
        public readonly int $minZoom,
        public readonly int $maxZoom,
        public readonly ?Bounds $bounds,
        public readonly bool $autoBounds
    ) {
        $errors = [];
        if ($minZoom < 0 || $maxZoom > self::MAX_ZOOM || $minZoom > $maxZoom) {
            $errors['viewport.zoomLimits'] = 'Zoom limits must satisfy 0 <= min <= max <= ' . self::MAX_ZOOM . '.';
        } elseif ($zoom < $minZoom || $zoom > $maxZoom) {
            $errors['viewport.zoom'] = 'Zoom must be within the zoom limits.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    public static function default(): self
    {
        return new self(new Coordinate(10.0, 51.0), 5.0, 0, 19, null, true);
    }

    /**
     * @param array<string, mixed> $data
     * @param self|null $base values used for keys missing in $data
     */
    public static function fromArray(array $data, ?self $base = null): self
    {
        $base ??= self::default();
        $center = isset($data['center']) ? Coordinate::fromPosition($data['center']) : $base->center;
        $bounds = array_key_exists('bounds', $data) ? ($data['bounds'] === null ? null : Bounds::fromArray($data['bounds'])) : $base->bounds;

        return new self(
            $center,
            self::number($data, 'zoom', $base->zoom),
            (int) self::number($data, 'minZoom', $base->minZoom),
            (int) self::number($data, 'maxZoom', $base->maxZoom),
            $bounds,
            self::boolean($data, 'autoBounds', $base->autoBounds)
        );
    }

    /** @return array{center: array{0: float, 1: float}, zoom: float, minZoom: int, maxZoom: int, bounds: array{0: float, 1: float, 2: float, 3: float}|null, autoBounds: bool} */
    public function toArray(): array
    {
        return [
            'center' => $this->center->toPosition(),
            'zoom' => $this->zoom,
            'minZoom' => $this->minZoom,
            'maxZoom' => $this->maxZoom,
            'bounds' => $this->bounds?->toArray(),
            'autoBounds' => $this->autoBounds,
        ];
    }

    /** @param array<string, mixed> $data */
    private static function number(array $data, string $key, float|int $default): float
    {
        if (!array_key_exists($key, $data)) {
            return (float) $default;
        }
        if (!is_int($data[$key]) && !is_float($data[$key])) {
            throw ValidationException::field('viewport.' . $key, 'Must be a number.');
        }

        return (float) $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function boolean(array $data, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }
        if (!is_bool($data[$key])) {
            throw ValidationException::field('viewport.' . $key, 'Must be a boolean.');
        }

        return $data[$key];
    }
}
