<?php
namespace PrymeOperator\Admin;

use PrymeOperator\Security\Guard;

final class Page {

	private const SLUG = 'prymeoperator';

	public function hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function menu(): void {
		add_menu_page(
			__( 'PrymeOperator', 'prymeoperator' ),
			__( 'PrymeOperator', 'prymeoperator' ),
			Guard::server_capability(),
			self::SLUG,
			[ $this, 'render' ],
			'dashicons-portfolio',
			81
		);
	}

	public function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'prymeoperator-admin', PRYMEOPERATOR_URL . 'assets/admin.css', [ 'wp-components' ], PRYMEOPERATOR_VERSION );
		wp_enqueue_script( 'prymeoperator-admin', PRYMEOPERATOR_URL . 'assets/admin.js', [ 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-url', 'wp-i18n' ], PRYMEOPERATOR_VERSION, true );
		wp_set_script_translations( 'prymeoperator-admin', 'prymeoperator', PRYMEOPERATOR_DIR . 'languages' );
	}

	public function render(): void {
		if ( ! Guard::can_read_server() ) {
			wp_die( esc_html__( 'You do not have permission to use PrymeOperator.', 'prymeoperator' ), 403 );
		}
		echo '<div class="wrap"><div id="prymeoperator-app"></div></div>';
	}
}
