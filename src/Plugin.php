<?php
namespace PrymeOperator;

use PrymeOperator\Admin\Page;
use PrymeOperator\Core\Installer;
use PrymeOperator\Core\Services;
use PrymeOperator\Core\Settings;
use PrymeOperator\Rest\AdminController;
use PrymeOperator\Library\Integration;
use PrymeOperator\Rest\AuditController;
use PrymeOperator\Rest\BackupController;
use PrymeOperator\Rest\LibraryController;
use PrymeOperator\Rest\ServerController;

final class Plugin {

	public const CRON_HOOK   = 'prymeoperator_daily';
	public const BACKUP_HOOK = 'prymeoperator_backup';

	public static function boot(): void {
		Installer::maybe_upgrade();

		add_action( 'init', static function (): void {
			load_plugin_textdomain( 'prymeoperator', false, dirname( plugin_basename( PRYMEOPERATOR_FILE ) ) . '/languages' );
		} );

		add_action( 'rest_api_init', static function (): void {
			( new ServerController() )->register_routes();
			( new AdminController() )->register_routes();
			( new AuditController() )->register_routes();
			( new LibraryController() )->register_routes();
			( new BackupController() )->register_routes();
		} );

		add_action( self::CRON_HOOK, [ self::class, 'daily' ] );
		add_action( self::BACKUP_HOOK, [ self::class, 'scheduled_backup' ] );
		add_action( 'prymeoperator_settings_saved', [ self::class, 'schedule_backups' ] );

		( new Integration() )->hooks();

		if ( is_admin() ) {
			( new Page() )->hooks();
		}
	}

	/**
	 * Empties expired trash and abandoned upload chunks.
	 */
	public static function daily(): void {
		Services::trash()->purge_older_than( (int) Settings::get( 'trash_days' ) );
		foreach ( glob( Services::vault()->dir( 'tmp' ) . '/*' ) ?: [] as $file ) {
			if ( is_file( $file ) && filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				@unlink( $file );
			}
		}
	}

	/**
	 * Keeps the backup event in line with the settings.
	 */
	public static function schedule_backups(): void {
		$recurrence = (string) Settings::get( 'backup_schedule' );
		$next       = wp_next_scheduled( self::BACKUP_HOOK );
		$current    = $next ? wp_get_schedule( self::BACKUP_HOOK ) : false;
		if ( $current === $recurrence ) {
			return;
		}
		wp_clear_scheduled_hook( self::BACKUP_HOOK );
		if ( in_array( $recurrence, [ 'daily', 'weekly' ], true ) ) {
			// First run tonight at 03:00 site time, when traffic is usually lowest.
			$first = ( new \DateTimeImmutable( 'tomorrow 03:00', wp_timezone() ) )->getTimestamp();
			wp_schedule_event( $first, $recurrence, self::BACKUP_HOOK );
		}
	}

	public static function scheduled_backup(): void {
		$backups = new \PrymeOperator\Backup\Backups( Services::vault() );
		try {
			$job    = $backups->start( (string) Settings::get( 'backup_files' ), true, '', true );
			$result = $backups->run( $job['id'] );
			\PrymeOperator\Operator\Audit::log( 'done' === $result['status'] ? 'backup.done' : 'backup.failed', $job['id'], [ 'automatic' => true, 'error' => $result['error'] ] );
			$backups->prune_automatic( (int) Settings::get( 'backup_keep' ) );
		} catch ( \Throwable $e ) {
			\PrymeOperator\Operator\Audit::log( 'backup.failed', 'automatic', [ 'error' => $e->getMessage() ] );
		}
	}
}
