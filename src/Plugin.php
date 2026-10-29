<?php
namespace PrymeOperator;

use PrymeOperator\Admin\Page;
use PrymeOperator\Core\Installer;
use PrymeOperator\Core\Services;
use PrymeOperator\Core\Settings;
use PrymeOperator\Rest\AdminController;
use PrymeOperator\Rest\AuditController;
use PrymeOperator\Rest\ServerController;

final class Plugin {

	public const CRON_HOOK = 'prymeoperator_daily';

	public static function boot(): void {
		Installer::maybe_upgrade();

		add_action( 'init', static function (): void {
			load_plugin_textdomain( 'prymeoperator', false, dirname( plugin_basename( PRYMEOPERATOR_FILE ) ) . '/languages' );
		} );

		add_action( 'rest_api_init', static function (): void {
			( new ServerController() )->register_routes();
			( new AdminController() )->register_routes();
			( new AuditController() )->register_routes();
		} );

		add_action( self::CRON_HOOK, [ self::class, 'daily' ] );

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
}
