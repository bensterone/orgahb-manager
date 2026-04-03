<?php
/**
 * Uninstall handler for OrgaHB Manager.
 *
 * Called automatically by WordPress when the plugin is deleted via the Plugins
 * screen. Drops the custom database table, removes roles, and cleans up options
 * and transients.
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

// If uninstall is not called from WordPress, bail immediately.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-capabilities.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-install.php';

ORGAHB_Install::uninstall();
