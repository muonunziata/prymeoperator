<?php
namespace PrymeOperator\Core;

final class Installer {

	public const DB_VERSION = '1';
	private const OPTION    = 'prymeoperator_db_version';

	public static function activate(): void {
		self::install();
	}

	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	private static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . 'prymeoperator_audit';

		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(64) NOT NULL,
			target TEXT NOT NULL,
			meta LONGTEXT NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY action (action),
			KEY created_at (created_at)
		) {$charset};" );

		update_option( self::OPTION, self::DB_VERSION, false );
	}

	public static function uninstall(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}prymeoperator_audit" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		delete_option( self::OPTION );
		delete_option( 'prymeoperator_settings' );
	}
}
