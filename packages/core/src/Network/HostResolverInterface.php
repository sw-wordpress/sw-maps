<?php

declare(strict_types=1);

namespace SWMaps\Core\Network;

interface HostResolverInterface
{
    /** @return list<string> every IPv4/IPv6 address the host resolves to (empty when it does not resolve) */
    public function resolve(string $host): array;
}
