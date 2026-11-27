<?php

declare(strict_types=1);

namespace SWMaps\Core\Network;

/** A server side request target was refused (scheme, credentials, private network, too many redirects). */
final class UnsafeUrlException extends \RuntimeException
{
}
