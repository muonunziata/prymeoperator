<?php
namespace PrymeOperator;

use PrymeOperator\Admin\Page;
use PrymeOperator\Core\Installer;
use PrymeOperator\Rest\AuditController;
use PrymeOperator\Rest\ServerController;

final class Plugin {

	public static function boot(): void {
		Installer::maybe_upgrade();

		add_action( 'rest_api_init', static function (): void {
			( new ServerController() )->register_routes();
			( new AuditController() )->register_routes();
		} );

		if ( is_admin() ) {
			( new Page() )->hooks();
		}
	}
}
