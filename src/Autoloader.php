<?php
namespace PrymeOperator;

/**
 * Minimal PSR-4 autoloader so the plugin runs without `composer install`.
 */
final class Autoloader {

	private const PREFIX = 'PrymeOperator\\';

	public static function register( string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class ) use ( $base_dir ): void {
				if ( ! str_starts_with( $class, self::PREFIX ) ) {
					return;
				}
				$relative = substr( $class, strlen( self::PREFIX ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_file( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
