<?php
/**
 * Plugin Name:       OrgaHB Manager — Organisational Handbook
 * Plugin URI:        https://github.com/your-org/orgahb-manager
 * Description:       A fully featured organisational handbook for small and medium-sized organisations. Self-hosted, open-source alternative to commercial handbook tools.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       orgahb-manager
 * Domain Path:       /languages
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// ── Constants ────────────────────────────────────────────────────────────────
define( 'ORGAHB_VERSION',     '1.0.0' );
define( 'ORGAHB_PLUGIN_FILE', __FILE__ );
define( 'ORGAHB_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'ORGAHB_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'ORGAHB_MIN_WP',      '6.8' );
define( 'ORGAHB_MIN_PHP',     '8.1' );
define( 'ORGAHB_DB_VERSION',  '1.0.0' );

// ── Core includes ────────────────────────────────────────────────────────────
require_once ORGAHB_PLUGIN_DIR . 'includes/class-install.php';
require_once ORGAHB_PLUGIN_DIR . 'includes/class-capabilities.php';
require_once ORGAHB_PLUGIN_DIR . 'includes/class-taxonomy.php';
require_once ORGAHB_PLUGIN_DIR . 'includes/class-cpt.php';
require_once ORGAHB_PLUGIN_DIR . 'admin/class-admin.php';
require_once ORGAHB_PLUGIN_DIR . 'includes/class-plugin.php';

// ── Lifecycle hooks ──────────────────────────────────────────────────────────
register_activation_hook( __FILE__, array( 'ORGAHB_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ORGAHB_Install', 'deactivate' ) );

// ── Boot ─────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', array( 'ORGAHB_Plugin', 'get_instance' ) );
