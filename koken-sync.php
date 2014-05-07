<?php
/**
 * Plugin Name:		Koken Sync
 * Description:		Use Koken as an image service for publishing albums in WordPress.
 * Version:			0.2.0
 * Author:			Darin Reid
 * Author URI: 		http://elcontraption.com/
 * License:			GPL-2.0+
 * License URI:		http://www.gnu.org/licenses/gpl-2.0.txt
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once( plugin_dir_path( __FILE__ ) . 'class-koken-sync.php' );

/**
 * Activation/deactivation hooks
 */
register_activation_hook( __FILE__, array( 'KokenSync', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'KokenSync', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'KokenSync', 'get_instance' ) );

/**
 * Admin only
 */
if ( is_admin() ) {

	require_once( plugin_dir_path( __FILE__ ) . 'class-koken-sync-admin.php' );
	require_once( plugin_dir_path( __FILE__ ) . 'class-koken-sync-settings.php' );

	add_action( 'plugins_loaded', array( 'KokenSyncAdmin', 'get_instance' ) );
	add_action( 'plugins_loaded', array( 'KokenSyncSettings', 'get_instance' ) );
}