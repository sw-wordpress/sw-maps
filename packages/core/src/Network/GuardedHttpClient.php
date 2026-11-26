<?php

declare(strict_types=1);

namespace SWMaps\Core\Network;

/** GET requests whose target and every redirect hop pass the {@see UrlGuard}. */
final class GuardedHttpClient
{
    public const MAX_REDIRECTS = 3;

    public function __construct(
        private readonly HttpTransportInterface $transport,
        private readonly UrlGuard $guard,
        private readonly int $timeoutSeconds = 5,
        private readonly int $maxBytes = 1048576
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @throws UnsafeUrlException
     * @throws HttpException
     */
    public function get(string $url, array $headers = [], ?int $maxBytes = null): HttpResponse
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $this->guard->check($url);
            $response = $this->transport->get($url, $headers, $this->timeoutSeconds, $maxBytes ?? $this->maxBytes);
            $location = $response->header('location');
            if ($response->status >= 300 && $response->status < 400 && $location !== null) {
                $url = UrlGuard::resolveRedirect($location, $url);
                continue;
            }

            return $response;
        }

        throw new UnsafeUrlException('Too many redirects.');
    }
}
