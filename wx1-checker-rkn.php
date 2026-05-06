<?php
/**
 * Plugin Name:       WX1 RKN Checker
 * Plugin URI:        https://github.com/Wx1000-inc/wx1-checker-rkn
 * Description:       Пакетная асинхронная проверка сайтов: Google Analytics, Яндекс.Метрика, Sape, LiveInternet, политика конфиденциальности, комментарии.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            WX1000-inc
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wx1-checker-rkn
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WXRKN_VERSION', '1.0.0' );
define( 'WXRKN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WXRKN_URL', plugin_dir_url( __FILE__ ) );
define( 'WXRKN_SLUG', 'wx1-checker-rkn' );

foreach ( array( 'class-database', 'class-checker', 'class-admin', 'class-ajax' ) as $file ) {
	require_once WXRKN_DIR . 'includes/' . $file . '.php';
}

register_activation_hook( __FILE__, array( 'WXRKN_Database', 'create_tables' ) );

add_action(
	'plugins_loaded',
	function () {
		new WXRKN_Admin();
		new WXRKN_Ajax();
	}
);
