<?php
namespace PrymeOperator\Core;

final class Installer {

	public const DB_VERSION = '2';
	private const OPTION    = 'prymeoperator_db_version';

	public static function activate(): void {
		self::install();
		if ( ! wp_next_scheduled( \PrymeOperator\Plugin::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', \PrymeOperator\Plugin::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( \PrymeOperator\Plugin::CRON_HOOK );
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

		// Library folders (Media Library, Pages, Posts…). An item lives in at most one folder.
		$folders = $wpdb->prefix . 'prymeoperator_folders';
		dbDelta( "CREATE TABLE {$folders} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			scope VARCHAR(20) NOT NULL,
			name VARCHAR(190) NOT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY scope_parent (scope,parent_id)
		) {$charset};" );

		$items = $wpdb->prefix . 'prymeoperator_folder_items';
		dbDelta( "CREATE TABLE {$items} (
			object_id BIGINT UNSIGNED NOT NULL,
			folder_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (object_id),
			KEY folder_id (folder_id)
		) {$charset};" );

		update_option( self::OPTION, self::DB_VERSION, false );
	}

	/**
	 * Data (audit log, trash, versions, settings) is kept unless the
	 * administrator opted in to removing it.
	 */
	public static function uninstall(): void {
		global $wpdb;
		wp_clear_scheduled_hook( \PrymeOperator\Plugin::CRON_HOOK );
		if ( ! Settings::get( 'delete_data_on_uninstall' ) ) {
			return;
		}
		$vault = Services::vault();
		if ( is_dir( $vault->base() ) ) {
			\PrymeOperator\Server\Fs::remove( $vault->base() );
		}
		foreach ( [ 'prymeoperator_audit', 'prymeoperator_folders', 'prymeoperator_folder_items' ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		delete_option( 'prymeoperator_mediaflow_map' );
		delete_option( self::OPTION );
		delete_option( 'prymeoperator_settings' );
	}
}
