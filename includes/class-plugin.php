<?php
/**
 * Main plugin bootstrap class.
 *
 * Loaded via `plugins_loaded` from the main plugin file.
 * Responsible for:
 *  - loading the text domain,
 *  - requiring all class files not already required by the main file,
 *  - and calling each component's static init() to register its hooks.
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Central bootstrap singleton.
 *
 * Usage: ORGAHB_Plugin::get_instance()  (called once from the main plugin file)
 */
final class ORGAHB_Plugin {

	/** @var ORGAHB_Plugin|null */
	private static ?ORGAHB_Plugin $instance = null;

	/**
	 * Returns (and on first call, creates) the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — enforces the singleton.
	 */
	private function __construct() {
		$this->load_textdomain();
		$this->require_components();
		$this->init_components();
	}

	// ── Prevent cloning / unserialization ────────────────────────────────────

	public function __clone() {}
	public function __wakeup() {}

	// ── Private methods ───────────────────────────────────────────────────────

	/**
	 * Loads the plugin text domain for translations.
	 *
	 * @return void
	 */
	private function load_textdomain(): void {
		load_plugin_textdomain(
			'orgahb-manager',
			false,
			dirname( plugin_basename( ORGAHB_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Requires all component class files.
	 *
	 * The main plugin file loads the bare minimum needed for lifecycle hooks
	 * (Install, Capabilities, Taxonomy, CPT, Plugin itself).
	 * Everything else is required here so that the main file stays lean.
	 *
	 * Add a require_once here as each class is implemented.
	 *
	 * @return void
	 */
	private function require_components(): void {
		$dir = ORGAHB_PLUGIN_DIR;

		// Loaded in dependency order — each file may reference classes above it.

		// Foundation: no dependencies.
		require_once $dir . 'includes/class-settings.php';
		require_once $dir . 'includes/class-audit-log.php';

		// QR depends on Settings.
		require_once $dir . 'includes/class-qr.php';

		// Buildings depends on QR + Building_Links (loaded next).
		require_once $dir . 'includes/class-building-links.php';
		require_once $dir . 'includes/class-buildings.php';

		// Workflow depends on Audit_Log + Settings.
		require_once $dir . 'includes/class-workflow.php';

		// Field data layers all depend on Audit_Log.
		require_once $dir . 'includes/class-acknowledgments.php';
		require_once $dir . 'includes/class-executions.php';
		require_once $dir . 'includes/class-observations.php';

		// Metaboxes depend on Buildings, QR, and Settings (all loaded above).
		require_once $dir . 'includes/class-metaboxes.php';

		// REST API depends on all data-layer classes.
		require_once $dir . 'includes/class-rest-api.php';

		// Search depends on REST API (uses ORGAHB_REST_API::NAMESPACE) + all data layers.
		require_once $dir . 'includes/class-search.php';

		// Export depends on all data-layer classes + Metaboxes (for META_* constants).
		require_once $dir . 'includes/class-export.php';

		// Privacy + cron depend on all data-layer classes + Settings.
		require_once $dir . 'includes/class-privacy.php';
		require_once $dir . 'includes/class-cron.php';

		// Backup ZIP generation depends on all data-layer classes.
		require_once $dir . 'includes/class-backup.php';

		// Feedback notifications depend on Buildings (META_* constants).
		require_once $dir . 'includes/class-feedback.php';

		// CSV import depends on Buildings + Building_Links.
		require_once $dir . 'includes/class-import.php';

		// SVG upload sanitization — no internal dependencies.
		require_once $dir . 'includes/class-svg.php';

		// Desktop handbook tree viewer ([orgahb_handbook] shortcode).
		require_once $dir . 'includes/class-handbook-tree.php';
	}

	/**
	 * Calls each component's static init() to register WordPress hooks.
	 *
	 * Components hook themselves via add_action / add_filter inside their
	 * own init() rather than doing work immediately — keeping boot fast.
	 *
	 * @return void
	 */
	private function init_components(): void {
		// Taxonomy at priority 5 so CPTs can declare the taxonomy on registration.
		ORGAHB_Taxonomy::init();

		// CPTs at priority 10 (default).
		ORGAHB_CPT::init();

		// Settings registers on admin_init — safe to call unconditionally.
		ORGAHB_Settings::init();

		// Buildings hooks: on_save ensures QR token + default areas; on_delete cleans links.
		ORGAHB_Buildings::init();

		// Workflow: registers orgahb_archived post status + display_post_states filter.
		ORGAHB_Workflow::init();

		ORGAHB_Metaboxes::init();
		ORGAHB_REST_API::init();
		ORGAHB_Search::init();
		ORGAHB_Export::init();

		if ( is_admin() ) {
			ORGAHB_Admin::init();
		}

		ORGAHB_Privacy::init();
		ORGAHB_Cron::init();
		ORGAHB_Backup::init();

		ORGAHB_Feedback::init();
		ORGAHB_Import::init();
		ORGAHB_SVG::init();
		ORGAHB_Handbook_Tree::init();
	}
}
