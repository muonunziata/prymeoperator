<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Autoloader.php';
\PrymeOperator\Autoloader::register( __DIR__ . '/src/' );

\PrymeOperator\Core\Installer::uninstall();
