<?php
namespace PrymeOperator\Security;

use PrymeOperator\Core\Settings;

/**
 * Single place that decides who may use which part of the plugin.
 *
 * Administrators (super admins on multisite) get the whole root. Roles that
 * an administrator opens up in the settings get a sub-folder, only the
 * operations chosen for them, and never blocked file types, code editing or
 * chmod. WordPress' DISALLOW_FILE_MODS / DISALLOW_FILE_EDIT switches apply to
 * everyone.
 */
final class Guard {

	private const WRITE_PERMISSIONS = [ 'upload', 'create', 'rename', 'copy', 'move', 'delete', 'archive', 'edit' ];

	public static function is_full_admin(): bool {
		if ( is_multisite() ) {
			return is_super_admin();
		}
		return current_user_can( (string) apply_filters( 'prymeoperator_admin_capability', 'manage_options' ) );
	}

	public static function has_role_access(): bool {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return false;
		}
		$roles = (array) Settings::get( 'role_access' );
		return [] !== array_intersect( $roles, (array) $user->roles );
	}

	public static function can_read_server(): bool {
		return self::is_full_admin() || self::has_role_access();
	}

	public static function server_root(): string {
		if ( defined( 'PRYMEOPERATOR_ROOT' ) ) {
			$root = PRYMEOPERATOR_ROOT;
		} else {
			$root = (string) Settings::get( 'root_path' ) ?: ABSPATH;
		}
		return (string) apply_filters( 'prymeoperator_server_root', $root );
	}

	/**
	 * The folder the current user is confined to.
	 */
	public static function jail(): Jail {
		$root = self::server_root();
		if ( ! self::is_full_admin() ) {
			$sub  = trim( (string) Settings::get( 'role_root' ), '/' );
			$root = ( new Jail( $root ) )->resolve( $sub );
		}
		return new Jail( $root );
	}

	public static function policy(): Policy {
		$settings = Settings::all();
		$admin    = self::is_full_admin();
		$denied   = [];
		$mods_off = defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS;

		if ( $mods_off ) {
			$denied = self::WRITE_PERMISSIONS;
		} elseif ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			$denied = [ 'edit' ];
		}

		$max = (int) $settings['max_upload_mb'] * 1048576;

		return new Policy(
			$admin ? Settings::PERMISSIONS : (array) $settings['role_permissions'],
			Settings::parse_extensions( (string) $settings['blocked_extensions'] ),
			$admin && (bool) $settings['admins_bypass_blocklist'],
			$max,
			$admin && ! $mods_off,
			$admin && (bool) $settings['show_hidden'],
			$denied
		);
	}
}
