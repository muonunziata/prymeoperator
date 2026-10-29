<?php
namespace PrymeOperator\Operator;

use PrymeOperator\Core\Settings;

/**
 * Optional e-mail when files are deleted.
 */
final class Notifier {

	/**
	 * @param array<int, array<string, mixed>> $entries Trash entries.
	 */
	public static function deleted( array $entries ): void {
		if ( ! Settings::get( 'notify_on_delete' ) || [] === $entries ) {
			return;
		}
		$to   = (string) Settings::get( 'notify_email' ) ?: (string) get_option( 'admin_email' );
		$user = wp_get_current_user();

		$lines = array_map( static fn ( $e ) => '- ' . $e['original'], $entries );
		$body  = sprintf(
			/* translators: 1: user name, 2: site name */
			__( '%1$s moved these items to the PrymeOperator trash on %2$s:', 'prymeoperator' ),
			$user->display_name,
			get_bloginfo( 'name' )
		) . "\n\n" . implode( "\n", $lines ) . "\n\n" . __( 'They can be restored from the Trash tab.', 'prymeoperator' );

		wp_mail( $to, sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Files deleted', 'prymeoperator' ) ), $body );
	}
}
