<?php
namespace PrymeOperator\Admin;

use PrymeOperator\Core\Settings;
use PrymeOperator\Security\Guard;

final class Page {

	private const SLUG = 'prymeoperator';

	public function hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( PRYMEOPERATOR_FILE ), [ $this, 'action_links' ] );
	}

	public function menu(): void {
		if ( ! Guard::can_read_server() ) {
			return;
		}
		// 'read' because restricted roles may be granted access; render() and
		// every REST route check Guard again.
		add_menu_page(
			__( 'PrymeOperator', 'prymeoperator' ),
			__( 'PrymeOperator', 'prymeoperator' ),
			'read',
			self::SLUG,
			[ $this, 'render' ],
			'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M2 4.5A1.5 1.5 0 0 1 3.5 3h4l2 2h7A1.5 1.5 0 0 1 18 6.5v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 2 15.5v-11Zm8.6 3.2-3.4 3.4 3.4 3.4 1.1-1.1-2.3-2.3 2.3-2.3-1.1-1.1Zm2.3 0-1.1 1.1 2.3 2.3-2.3 2.3 1.1 1.1 3.4-3.4-3.4-3.4Z"/></svg>' ),
			81
		);
	}

	public function action_links( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ), esc_html__( 'Open', 'prymeoperator' ) ) );
		return $links;
	}

	public function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		// Loads WordPress' bundled CodeMirror (with PHP, JS, CSS, HTML, SQL… modes).
		$editor = wp_enqueue_code_editor( [ 'type' => 'application/x-httpd-php' ] );

		wp_enqueue_style( 'prymeoperator-admin', PRYMEOPERATOR_URL . 'assets/admin.css', [ 'wp-components' ], PRYMEOPERATOR_VERSION );
		wp_enqueue_script(
			'prymeoperator-admin',
			PRYMEOPERATOR_URL . 'assets/admin.js',
			[ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-url', 'wp-i18n', 'code-editor' ],
			PRYMEOPERATOR_VERSION,
			true
		);
		wp_set_script_translations( 'prymeoperator-admin', 'prymeoperator', PRYMEOPERATOR_DIR . 'languages' );

		$max_upload = (int) wp_max_upload_size();
		wp_localize_script( 'prymeoperator-admin', 'PrymeOperator', [
			'rest'       => esc_url_raw( rest_url( 'prymeoperator/v1/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'isAdmin'    => Guard::is_full_admin(),
			'theme'      => Settings::get( 'theme' ),
			'view'       => Settings::get( 'default_view' ),
			'chunkSize'  => max( 262144, min( 2 * MB_IN_BYTES, $max_upload - 65536 ) ),
			'editor'     => $editor ?: null,
			'version'    => PRYMEOPERATOR_VERSION,
			'canSeePath' => Guard::is_full_admin(),
		] );
	}

	public function render(): void {
		if ( ! Guard::can_read_server() ) {
			wp_die( esc_html__( 'You do not have permission to use PrymeOperator.', 'prymeoperator' ), 403 );
		}
		echo '<div class="wrap po-wrap"><div id="prymeoperator-app"></div></div>';
	}
}
