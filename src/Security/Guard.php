<?php
namespace PrymeOperator\Security;

/**
 * Single place that decides who may use which part of the plugin.
 *
 * Server mode is administrators only by default (super admins on multisite).
 * Role profiles that open it to other users arrive in a later phase; until
 * then the capability can only be raised through the filter, never lowered
 * to roles without `manage_options` by accident.
 */
final class Guard {

	public static function server_capability(): string {
		return (string) apply_filters( 'prymeoperator_server_capability', 'manage_options' );
	}

	public static function can_read_server(): bool {
		if ( is_multisite() && ! is_super_admin() ) {
			return false;
		}
		return current_user_can( self::server_capability() );
	}

	/**
	 * Writes additionally respect WordPress' own kill switches.
	 */
	public static function can_write_server(): bool {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return false;
		}
		return self::can_read_server();
	}

	public static function can_edit_code(): bool {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return false;
		}
		return self::can_write_server();
	}

	public static function server_root(): string {
		$root = defined( 'PRYMEOPERATOR_ROOT' ) ? PRYMEOPERATOR_ROOT : ABSPATH;
		return (string) apply_filters( 'prymeoperator_server_root', $root );
	}

	public static function jail(): Jail {
		return new Jail( self::server_root() );
	}
}
