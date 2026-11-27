<?php

declare(strict_types=1);

namespace SWMaps\Core\Geocoding;

use SWMaps\Core\Domain\FeatureProperties;
use SWMaps\Core\Geo\Coordinate;
use SWMaps\Core\Network\GuardedHttpClient;
use SWMaps\Core\Network\HttpException;
use SWMaps\Core\Network\UnsafeUrlException;

/**
 * Nominatim (public instance or self hosted). Requests are made only on an explicit action (no autocomplete), carry a
 * User-Agent that identifies the site, and are limited to one per second on the public instance (its usage policy).
 */
final class NominatimGeocoder implements GeocoderInterface
{
    public const PUBLIC_HOST = 'nominatim.openstreetmap.org';
    public const RATE_LIMIT_KEY = 'geocoder';
    public const MAX_RESPONSE_BYTES = 262144;

    public function __construct(
        private readonly GuardedHttpClient $http,
        private readonly RateLimiterInterface $limiter,
        private readonly string $baseUrl,
        private readonly string $contact,
        private readonly string $agent,
        private readonly ?string $apiKey = null
    ) {
    }

    public function search(string $query, int $limit = 5): array
    {
        $query = trim($query);
        $length = mb_strlen($query);
        if ($length < 3 || $length > 200) {
            throw new GeocodingException(GeocodingException::INVALID_QUERY, 'The search text must have 3 to 200 characters.');
        }
        $body = $this->request('/search', ['q' => $query, 'format' => 'jsonv2', 'limit' => (string) max(1, min(10, $limit)), 'addressdetails' => '0']);
        $results = [];
        foreach ($body as $row) {
            $result = is_array($row) ? $this->result($row) : null;
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    public function reverse(float $longitude, float $latitude): ?GeocodeResult
    {
        try {
            new Coordinate($longitude, $latitude);
        } catch (\Throwable) {
            throw new GeocodingException(GeocodingException::INVALID_QUERY, 'The coordinates are not valid.');
        }
        $body = $this->request('/reverse', ['lat' => (string) $latitude, 'lon' => (string) $longitude, 'format' => 'jsonv2', 'zoom' => '18']);

        return isset($body['display_name']) ? $this->result($body) : null;
    }

    /**
     * @param array<string, string> $params
     * @return array<mixed>
     */
    private function request(string $path, array $params): array
    {
        $base = rtrim($this->baseUrl, '/');
        if ($base === '') {
            throw new GeocodingException(GeocodingException::NOT_CONFIGURED, 'No geocoding server is configured.');
        }
        $host = (string) parse_url($base, PHP_URL_HOST);
        $public = strtolower($host) === self::PUBLIC_HOST;
        if ($public && trim($this->contact) === '') {
            throw new GeocodingException(GeocodingException::NOT_CONFIGURED, 'The public Nominatim server requires a contact address (usage policy).');
        }
        if (!$this->limiter->acquire(self::RATE_LIMIT_KEY, $public ? 1 : 0)) {
            throw new GeocodingException(GeocodingException::RATE_LIMITED, 'Too many requests; wait a moment.');
        }
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $params['key'] = $this->apiKey;
        }
        $headers = ['User-Agent' => trim($this->agent . ($this->contact !== '' ? ' (' . $this->contact . ')' : '')), 'Accept' => 'application/json'];
        try {
            $response = $this->http->get($base . $path . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986), $headers, self::MAX_RESPONSE_BYTES);
        } catch (UnsafeUrlException) {
            throw new GeocodingException(GeocodingException::NOT_ALLOWED, 'The geocoding server address is not allowed.');
        } catch (HttpException) {
            throw new GeocodingException(GeocodingException::UNAVAILABLE, 'The geocoding server is not reachable.');
        }
        if ($response->status === 429) {
            throw new GeocodingException(GeocodingException::RATE_LIMITED, 'The geocoding server limits requests.');
        }
        if ($response->status !== 200) {
            throw new GeocodingException(GeocodingException::UNAVAILABLE, sprintf('The geocoding server answered with status %d.', $response->status));
        }
        try {
            $data = json_decode($response->body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new GeocodingException(GeocodingException::UNAVAILABLE, 'The geocoding server sent an invalid answer.');
        }

        return is_array($data) ? $data : [];
    }

    /** @param array<mixed> $row */
    private function result(array $row): ?GeocodeResult
    {
        $lat = $row['lat'] ?? null;
        $lon = $row['lon'] ?? null;
        $label = $row['display_name'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon) || !is_string($label)) {
            return null;
        }
        try {
            $coordinate = new Coordinate((float) $lon, (float) $lat);
        } catch (\Throwable) {
            return null;
        }
        $clean = mb_substr(FeatureProperties::plain($label, false), 0, 300);

        return $clean === '' ? null : new GeocodeResult($clean, round($coordinate->longitude, 7), round($coordinate->latitude, 7));
    }
}
