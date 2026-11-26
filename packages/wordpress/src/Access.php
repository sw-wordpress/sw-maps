<?php
/**
 * Object level authorisation helpers (capability plus per-map filter).
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress;

final class Access {

	/**
	 * Whether the current user may edit the given map. The filter `swmap_can_edit_map` can further restrict access
	 * (e.g. own maps only); it can never grant more than the capability.
	 */
	public static function can_edit_map( int $map_id ): bool {
		if ( ! Capabilities::can( Capabilities::EDIT_MAPS ) ) {
			return false;
		}
		/**
		 * Filters whether the current user may edit a map.
		 *
		 * @param bool $allowed Whether editing is allowed.
		 * @param int  $map_id  Map ID.
		 * @param int  $user_id Current user ID.
		 */
		return true === apply_filters( 'swmap_can_edit_map', true, $map_id, get_current_user_id() );
	}

	/** Whether drafts and unpublished content of this map may be shown to the current user (preview). */
	public static function can_preview_map( int $map_id ): bool {
		return self::can_edit_map( $map_id );
	}
}
