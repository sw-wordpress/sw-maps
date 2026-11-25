<?php
/**
 * Capability model: separate capabilities for editing, publishing, layers, imports and system configuration.
 *
 * @package sw-maps
 */

declare(strict_types=1);

namespace SWMaps\WordPress;

final class Capabilities {

	public const EDIT_MAPS      = 'swmap_edit_maps';
	public const PUBLISH_MAPS   = 'swmap_publish_maps';
	public const MANAGE_LAYERS  = 'swmap_manage_layers';
	public const MANAGE_IMPORTS = 'swmap_manage_imports';
	public const MANAGE_SYSTEM  = 'swmap_manage_system';

	/**
	 * Role to capabilities mapping applied on activation (docs/hooks.md).
	 *
	 * @return array<string, list<string>>
	 */
	public static function role_map(): array {
		return array(
			'administrator' => array( self::EDIT_MAPS, self::PUBLISH_MAPS, self::MANAGE_LAYERS, self::MANAGE_IMPORTS, self::MANAGE_SYSTEM ),
			'editor'        => array( self::EDIT_MAPS, self::PUBLISH_MAPS, self::MANAGE_LAYERS ),
		);
	}

	/** @return list<string> */
	public static function all(): array {
		return array( self::EDIT_MAPS, self::PUBLISH_MAPS, self::MANAGE_LAYERS, self::MANAGE_IMPORTS, self::MANAGE_SYSTEM );
	}

	public static function grant(): void {
		foreach ( self::role_map() as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( null === $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	public static function revoke(): void {
		foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
			$role = get_role( (string) $role_name );
			if ( null === $role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * On multisite, super admins pass every check through WordPress core (`is_super_admin`).
	 */
	public static function can( string $cap ): bool {
		return current_user_can( $cap );
	}
}
