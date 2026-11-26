<?php

declare(strict_types=1);

namespace SWMaps\Core\Geocoding;

final class GeocodeResult
{
    public function __construct(public readonly string $label, public readonly float $longitude, public readonly float $latitude)
    {
    }

    /** @return array{label: string, lon: float, lat: float} */
    public function toArray(): array
    {
        return ['label' => $this->label, 'lon' => $this->longitude, 'lat' => $this->latitude];
    }
}
