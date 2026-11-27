<?php

declare(strict_types=1);

namespace SWMaps\Core\Geocoding;

interface GeocoderInterface
{
    /**
     * @return list<GeocodeResult>
     * @throws GeocodingException
     */
    public function search(string $query, int $limit = 5): array;

    /** @throws GeocodingException */
    public function reverse(float $longitude, float $latitude): ?GeocodeResult;
}
