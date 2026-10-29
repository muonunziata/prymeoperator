<?php
namespace PrymeOperator\Core;

/**
 * Plugin settings, stored in one option and always read through defaults()
 * so a missing or partial option never yields an unsafe value.
 */
final class Settings {

	public const OPTION = 'prymeoperator_settings';

	public const PERMISSIONS = [ 'upload', 'download', 'create', 'rename', 'copy', 'move', 'delete', 'archive', 'edit' ];

	public const DEFAULT_BLOCKED = 'php,php3,php4,php5,php7,php8,phtml,phar,pht,phps,shtml,cgi,pl,py,sh,bash,asp,aspx,jsp,exe,bat,cmd,com,htaccess,htpasswd,user.ini';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'root_path'                => '',
			'role_access'              => [],
			'role_root'                => 'wp-content/uploads',
			'role_permissions'         => [ 'upload', 'download', 'create', 'rename' ],
			'max_upload_mb'            => 0,
			'blocked_extensions'       => self::DEFAULT_BLOCKED,
			'admins_bypass_blocklist'  => true,
			'show_hidden'              => false,
			'php_lint'                 => true,
			'loopback_check'           => true,
			'versions_keep'            => 10,
			'trash_days'               => 30,
			'notify_on_delete'         => false,
			'notify_email'             => '',
			'theme'                    => 'auto',
			'default_view'             => 'list',
			'delete_data_on_uninstall' => false,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : [] );
	}

	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Validates and stores the given values. Unknown keys are ignored.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update( array $input ): array|\WP_Error {
		$current = self::all();
		$clean   = $current;

		foreach ( $input as $key => $value ) {
			if ( ! array_key_exists( $key, $current ) ) {
				continue;
			}
			switch ( $key ) {
				case 'root_path':
					$value = trim( (string) $value );
					if ( '' !== $value && ( ! path_is_absolute( $value ) || ! is_dir( $value ) ) ) {
						return new \WP_Error( 'prymeoperator_bad_root', __( 'The root folder must be an absolute path to an existing folder.', 'prymeoperator' ), [ 'status' => 400 ] );
					}
					$clean[ $key ] = $value;
					break;
				case 'role_access':
					$roles         = array_keys( wp_roles()->roles );
					$clean[ $key ] = array_values( array_intersect( array_map( 'strval', (array) $value ), $roles ) );
					break;
				case 'role_permissions':
					$clean[ $key ] = array_values( array_intersect( array_map( 'strval', (array) $value ), self::PERMISSIONS ) );
					break;
				case 'role_root':
					$clean[ $key ] = trim( str_replace( '\\', '/', (string) $value ), '/' );
					break;
				case 'blocked_extensions':
					$clean[ $key ] = implode( ',', self::parse_extensions( (string) $value ) );
					break;
				case 'notify_email':
					$value = sanitize_email( (string) $value );
					$clean[ $key ] = is_email( $value ) ? $value : '';
					break;
				case 'theme':
					$clean[ $key ] = in_array( $value, [ 'auto', 'light', 'dark' ], true ) ? $value : 'auto';
					break;
				case 'default_view':
					$clean[ $key ] = in_array( $value, [ 'list', 'grid' ], true ) ? $value : 'list';
					break;
				case 'max_upload_mb':
				case 'versions_keep':
				case 'trash_days':
					$clean[ $key ] = max( 0, min( 100000, (int) $value ) );
					break;
				default:
					$clean[ $key ] = (bool) $value;
			}
		}

		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * @return string[]
	 */
	public static function parse_extensions( string $list ): array {
		$parts = array_map( static fn ( $e ) => strtolower( trim( $e, " \t\n\r\0\x0B." ) ), explode( ',', $list ) );
		return array_values( array_unique( array_filter( $parts, static fn ( $e ) => '' !== $e && preg_match( '/^[a-z0-9._-]+$/', $e ) ) ) );
	}
}
