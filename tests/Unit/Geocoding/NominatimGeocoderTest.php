<?php

declare(strict_types=1);

namespace SWMaps\Tests\Unit\Geocoding;

use PHPUnit\Framework\TestCase;
use SWMaps\Core\Geocoding\GeocodingException;
use SWMaps\Core\Geocoding\NominatimGeocoder;
use SWMaps\Core\Geocoding\RateLimiterInterface;
use SWMaps\Core\Network\GuardedHttpClient;
use SWMaps\Core\Network\HostResolverInterface;
use SWMaps\Core\Network\HttpException;
use SWMaps\Core\Network\HttpResponse;
use SWMaps\Core\Network\HttpTransportInterface;
use SWMaps\Core\Network\UrlGuard;

final class NominatimGeocoderTest extends TestCase
{
    /** @var list<array{string, array<string, string>, int}> */
    private array $requests = [];
    /** @var list<array{string, int}> */
    private array $slots = [];

    /** @param list<HttpResponse|\Throwable> $responses */
    private function geocoder(array $responses, string $base = 'https://nominatim.openstreetmap.org', string $contact = 'admin@example.org', bool $limited = false, ?string $key = null): NominatimGeocoder
    {
        $self = $this;
        $transport = new class ($responses, $self) implements HttpTransportInterface {
            /** @param list<HttpResponse|\Throwable> $responses */
            public function __construct(private array $responses, private readonly NominatimGeocoderTest $test)
            {
            }

            public function get(string $url, array $headers, int $timeoutSeconds, int $maxBytes, array $addresses): HttpResponse
            {
                $this->test->record($url, $headers, $maxBytes);
                $next = array_shift($this->responses) ?? new HttpResponse(200, [], '[]');
                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next;
            }
        };
        $resolver = new class implements HostResolverInterface {
            public function resolve(string $host): array
            {
                return $host === 'geo.lan' ? ['10.0.0.8'] : ['93.184.216.34'];
            }
        };
        $limiter = new class ($self, $limited) implements RateLimiterInterface {
            public function __construct(private readonly NominatimGeocoderTest $test, private readonly bool $limited)
            {
            }

            public function acquire(string $key, int $intervalSeconds): bool
            {
                $this->test->slot($key, $intervalSeconds);

                return !$this->limited;
            }
        };
        $guard = new UrlGuard($resolver, []);

        return new NominatimGeocoder(new GuardedHttpClient($transport, $guard), $limiter, $base, $contact, 'SW-Maps/1.0 (https://site.example)', $key);
    }

    /** @param array<string, string> $headers */
    public function record(string $url, array $headers, int $max): void
    {
        $this->requests[] = [$url, $headers, $max];
    }

    public function slot(string $key, int $interval): void
    {
        $this->slots[] = [$key, $interval];
    }

    private static function json(mixed $data, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, [], (string) json_encode($data));
    }

    public function testSearchIdentifiesTheSiteLimitsTheRateAndParsesResults(): void
    {
        $rows = [
            ['lat' => '49.0069', 'lon' => '8.4037', 'display_name' => 'Karlsruhe <b>Schloss</b>, Deutschland'],
            ['lat' => 'x', 'lon' => '1', 'display_name' => 'broken'],
            ['lat' => '95', 'lon' => '1', 'display_name' => 'out of range'],
            ['lat' => '1', 'lon' => '1'],
            'junk',
            ['lat' => '1', 'lon' => '2', 'display_name' => '<i></i>'],
        ];
        $g = $this->geocoder([self::json($rows)]);
        $results = $g->search('  Schloss Karlsruhe ', 3);
        self::assertSame([['label' => 'Karlsruhe Schloss, Deutschland', 'lon' => 8.4037, 'lat' => 49.0069]], array_map(static fn ($r): array => $r->toArray(), $results));
        [$url, $headers, $max] = $this->requests[0];
        self::assertStringStartsWith('https://nominatim.openstreetmap.org/search?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame(['q' => 'Schloss Karlsruhe', 'format' => 'jsonv2', 'limit' => '3', 'addressdetails' => '0'], $query);
        self::assertSame('SW-Maps/1.0 (https://site.example) (admin@example.org)', $headers['User-Agent']);
        self::assertSame(NominatimGeocoder::MAX_RESPONSE_BYTES, $max);
        self::assertSame([['geocoder', 1]], $this->slots, 'the public instance allows one request per second');
    }

    public function testSelfHostedServerNeedsNoContactAndHasNoRateLimit(): void
    {
        $g = $this->geocoder([self::json([])], 'https://geo.example.org/', '');
        self::assertSame([], $g->search('Berlin'));
        self::assertSame([['geocoder', 0]], $this->slots);
        self::assertSame('SW-Maps/1.0 (https://site.example)', $this->requests[0][1]['User-Agent']);
        self::assertStringStartsWith('https://geo.example.org/search?', $this->requests[0][0]);
    }

    public function testApiKeyIsSentToTheServerOnly(): void
    {
        $g = $this->geocoder([self::json([])], 'https://geo.example.org', '', false, 's3cret');
        $g->search('Berlin');
        self::assertStringContainsString('key=s3cret', $this->requests[0][0]);
    }

    /** @return iterable<string, array{callable(self): mixed, string}> */
    public static function failures(): iterable
    {
        yield 'too short' => [fn (self $t) => $t->geocoder([])->search('ab'), GeocodingException::INVALID_QUERY];
        yield 'too long' => [fn (self $t) => $t->geocoder([])->search(str_repeat('a', 201)), GeocodingException::INVALID_QUERY];
        yield 'no server' => [fn (self $t) => $t->geocoder([], '')->search('Berlin'), GeocodingException::NOT_CONFIGURED];
        yield 'public without contact' => [fn (self $t) => $t->geocoder([], 'https://nominatim.openstreetmap.org', ' ')->search('Berlin'), GeocodingException::NOT_CONFIGURED];
        yield 'rate limited here' => [fn (self $t) => $t->geocoder([], 'https://nominatim.openstreetmap.org', 'a@b.c', true)->search('Berlin'), GeocodingException::RATE_LIMITED];
        yield 'rate limited there' => [fn (self $t) => $t->geocoder([self::json([], 429)])->search('Berlin'), GeocodingException::RATE_LIMITED];
        yield 'server error' => [fn (self $t) => $t->geocoder([self::json([], 500)])->search('Berlin'), GeocodingException::UNAVAILABLE];
        yield 'unreachable' => [fn (self $t) => $t->geocoder([new HttpException('down')])->search('Berlin'), GeocodingException::UNAVAILABLE];
        yield 'invalid json' => [fn (self $t) => $t->geocoder([new HttpResponse(200, [], '<html>')])->search('Berlin'), GeocodingException::UNAVAILABLE];
        yield 'private server' => [fn (self $t) => $t->geocoder([], 'http://geo.lan')->search('Berlin'), GeocodingException::NOT_ALLOWED];
        yield 'bad reverse coordinates' => [fn (self $t) => $t->geocoder([])->reverse(999, 1), GeocodingException::INVALID_QUERY];
    }

    public function testEveryFailureHasItsReason(): void
    {
        foreach (self::failures() as $name => [$call, $reason]) {
            try {
                $call($this);
                self::fail("$name: expected a GeocodingException");
            } catch (GeocodingException $e) {
                self::assertSame($reason, $e->reason, $name);
            }
            $this->requests = [];
            $this->slots = [];
        }
    }

    public function testReverseReturnsTheAddressOrNothing(): void
    {
        $g = $this->geocoder([self::json(['lat' => '49.0', 'lon' => '8.4', 'display_name' => 'Kaiserstraße 1, Karlsruhe']), self::json(['error' => 'Unable to geocode'])]);
        $result = $g->reverse(8.4, 49.0);
        self::assertSame('Kaiserstraße 1, Karlsruhe', $result?->label);
        parse_str((string) parse_url($this->requests[0][0], PHP_URL_QUERY), $query);
        self::assertSame(['lat' => '49', 'lon' => '8.4', 'format' => 'jsonv2', 'zoom' => '18'], $query);
        self::assertNull($g->reverse(0.0, 0.0));
    }
}
