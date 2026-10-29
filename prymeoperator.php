<?php
/**
 * Plugin Name:       PrymeOperator
 * Description:       Secure file operator for WordPress: server file manager, media library folders and reversible, audited operations.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            PrymeOperator
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       prymeoperator
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'PRYMEOPERATOR_VERSION', '0.1.0' );
define( 'PRYMEOPERATOR_FILE', __FILE__ );
define( 'PRYMEOPERATOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRYMEOPERATOR_URL', plugin_dir_url( __FILE__ ) );

require_once PRYMEOPERATOR_DIR . 'src/Autoloader.php';
\PrymeOperator\Autoloader::register( PRYMEOPERATOR_DIR . 'src/' );

register_activation_hook( __FILE__, [ \PrymeOperator\Core\Installer::class, 'activate' ] );

add_action( 'plugins_loaded', [ \PrymeOperator\Plugin::class, 'boot' ] );
