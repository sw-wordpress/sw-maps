<?php

declare(strict_types=1);

namespace SWMaps\Core\Network;

interface HttpTransportInterface
{
    /**
     * One request; redirects are NOT followed (the guarded client checks every hop).
     *
     * @param array<string, string> $headers
     * @param non-empty-list<string> $addresses The only IP addresses the connection may use.
     * @throws HttpException
     */
    public function get(string $url, array $headers, int $timeoutSeconds, int $maxBytes, array $addresses): HttpResponse;
}
