<?php
/**
 * System clock (UTC).
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress\Persistence;

use SWMaps\Core\Contracts\ClockInterface;

final class SystemClock implements ClockInterface {

	public function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
