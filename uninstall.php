<?php
/**
 * Runs when the plugin is uninstalled via the WordPress admin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-database.php';
WXRKN_Database::drop_tables();
