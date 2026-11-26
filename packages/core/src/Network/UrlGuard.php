<?php

declare(strict_types=1);

namespace SWMaps\Core\Network;

/**
 * Decides whether the server may request a URL: http(s) only, no credentials in the URL, and the host must resolve
 * to public addresses only. Hosts on the admin allowlist (self hosted tile or geocoding servers in a private
 * network) are exempt from the address check, nothing else is.
 */
final class UrlGuard
{
    /** @param list<string> $allowedHosts exact host names the administrator allows even in private networks */
    public function __construct(private readonly HostResolverInterface $resolver, private readonly array $allowedHosts = [])
    {
    }

    /** @throws UnsafeUrlException */
    public function check(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeUrlException('The URL is not valid.');
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new UnsafeUrlException('Only http and https URLs are allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('URLs with credentials are not allowed.');
        }
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            throw new UnsafeUrlException('The URL contains forbidden characters.');
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '' || str_ends_with($host, '.')) {
            throw new UnsafeUrlException('The host is not valid.');
        }
        if (in_array($host, array_map('strtolower', $this->allowedHosts), true)) {
            return;
        }
        // Numeric shorthand ("2130706433", "0x7f.1", "127.1") is read as an address by many resolvers: refuse it outright.
        if (!self::isIpLiteral($host) && preg_match('/^[0-9a-fx.]+$/i', $host) === 1) {
            throw new UnsafeUrlException('Numeric host notations are not allowed.');
        }
        $addresses = self::isIpLiteral($host) ? [$host] : $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new UnsafeUrlException('The host does not resolve.');
        }
        foreach ($addresses as $address) {
            if (!self::isPublic($address)) {
                throw new UnsafeUrlException('The target is in a private or reserved network.');
            }
        }
    }

    /** Resolves a redirect target against the URL it came from. */
    public static function resolveRedirect(string $location, string $base): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'http') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');

        return $origin . $dir . $location;
    }

    public static function isIpLiteral(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /** True for globally routable addresses (IPv4 and IPv6, including IPv4-mapped IPv6). */
    public static function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $m) === 1) {
            return self::isPublic($m[1]);
        }
        if (str_contains($address, ':')) {
            $packed = inet_pton($address);
            if ($packed === false) {
                return false;
            }
            $first = ord($packed[0]);
            $second = ord($packed[1]);
            // ::/128 unspecified, ::1 loopback, fc00::/7 unique local, fe80::/10 link local, ff00::/8 multicast, 2001:db8::/32 documentation
            if ($packed === str_repeat("\0", 16) || $packed === str_repeat("\0", 15) . "\x01") {
                return false;
            }
            if (($first & 0xFE) === 0xFC || ($first === 0xFE && ($second & 0xC0) === 0x80) || $first === 0xFF) {
                return false;
            }
            if ($first === 0x20 && $second === 0x01 && ord($packed[2]) === 0x0D && ord($packed[3]) === 0xB8) {
                return false;
            }
            // IPv4-compatible / NAT64 well known prefix embed an IPv4 address: judge that one.
            if (str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\0\0") || str_starts_with($packed, "\x00\x64\xFF\x9B" . str_repeat("\0", 8))) {
                return self::isPublic(inet_ntop(substr($packed, 12)) ?: '0.0.0.0');
            }

            return true;
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && !self::inRange($address, '100.64.0.0', 10)   // carrier grade NAT
            && !self::inRange($address, '192.0.0.0', 24)    // IETF protocol assignments
            && !self::inRange($address, '198.18.0.0', 15)   // benchmarking
            && !self::inRange($address, '224.0.0.0', 4);    // multicast
    }

    private static function inRange(string $address, string $base, int $bits): bool
    {
        $a = ip2long($address);
        $b = ip2long($base);
        if ($a === false || $b === false) {
            return false;
        }
        $mask = -1 << (32 - $bits);

        return ($a & $mask) === ($b & $mask);
    }
}
