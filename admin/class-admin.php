<?php
/**
 * Registers the admin menu, submenu pages, and admin-side assets.
 *
 * The top-level "Handbook" menu (slug: orgahb-handbook) is registered here.
 * The four CPTs declare `show_in_menu => 'orgahb-handbook'` in class-cpt.php
 * so WordPress appends them as sub-items automatically.
 *
 * Explicitly-registered submenus:
 *   Settings     — plugin options via the Settings API
 *   Reports      — acknowledgment / evidence / observation data (future React shell)
 *   Backup & Export — admin-triggered data export / uninstall backup
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu, page rendering, and asset enqueueing.
 */
final class ORGAHB_Admin {

	// ── Hook registration ─────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu',             array( self::class, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts',  array( self::class, 'enqueue_assets' ) );
	}

	// ── Menu registration ─────────────────────────────────────────────────────

	/**
	 * Registers the top-level Handbook menu and plugin-specific submenus.
	 *
	 * @return void
	 */
	public static function register_menus(): void {

		// Top-level menu.  CPT submenu items attach to this slug automatically
		// because they declare show_in_menu = 'orgahb-handbook'.
		add_menu_page(
			__( 'Handbook', 'orgahb-manager' ),
			__( 'Handbook', 'orgahb-manager' ),
			'read_orgahb_content',
			'orgahb-handbook',
			array( self::class, 'render_dashboard' ),
			'dashicons-book-alt',
			30
		);

		// Settings — manage_options only (WordPress administrators).
		add_submenu_page(
			'orgahb-handbook',
			__( 'Handbook Settings', 'orgahb-manager' ),
			__( 'Settings', 'orgahb-manager' ),
			'manage_options',
			'orgahb-settings',
			array( self::class, 'render_settings_page' )
		);

		// Reports — reviewers and above (publish_orgahb_contents capability).
		add_submenu_page(
			'orgahb-handbook',
			__( 'Handbook Reports', 'orgahb-manager' ),
			__( 'Reports', 'orgahb-manager' ),
			'publish_orgahb_contents',
			'orgahb-reports',
			array( self::class, 'render_reports_page' )
		);

		// Backup & Export — manage_options only.
		add_submenu_page(
			'orgahb-handbook',
			__( 'Backup & Export', 'orgahb-manager' ),
			__( 'Backup & Export', 'orgahb-manager' ),
			'manage_options',
			'orgahb-backup-export',
			array( self::class, 'render_backup_export_page' )
		);
	}

	// ── Asset enqueueing ──────────────────────────────────────────────────────

	/**
	 * Enqueues CSS/JS only on plugin-owned admin pages.
	 *
	 * @param string $hook_suffix  Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		$plugin_pages = array(
			'toplevel_page_orgahb-handbook',
			'handbook_page_orgahb-settings',
			'handbook_page_orgahb-reports',
			'handbook_page_orgahb-backup-export',
		);

		if ( ! in_array( $hook_suffix, $plugin_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'orgahb-admin',
			ORGAHB_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			ORGAHB_VERSION
		);

		// Bundle manager React app — only on the dashboard landing page.
		if ( 'toplevel_page_orgahb-handbook' === $hook_suffix ) {
			wp_enqueue_script( 'wp-api-fetch' );
			wp_add_inline_script(
				'wp-api-fetch',
				sprintf(
					'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( %s ) );',
					wp_json_encode( wp_create_nonce( 'wp_rest' ) )
				),
				'after'
			);

			wp_enqueue_script(
				'orgahb-admin-shell',
				ORGAHB_PLUGIN_URL . 'assets/dist/admin-shell.js',
				array( 'wp-api-fetch' ),
				ORGAHB_VERSION,
				true
			);

			wp_localize_script(
				'orgahb-admin-shell',
				'orgahbAdminConfig',
				array(
					'restUrl' => rest_url( 'orgahb/v1/' ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				)
			);
		}
	}

	// ── Page renderers ────────────────────────────────────────────────────────

	/**
	 * Dashboard landing page (top-level menu click target).
	 *
	 * @return void
	 */
	public static function render_dashboard(): void {
		if ( ! current_user_can( 'read_orgahb_content' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'orgahb-manager' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Handbook', 'orgahb-manager' ); ?></h1>
			<div id="orgahb-admin-shell"></div>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'orgahb-manager' ) );
		}
		require_once ORGAHB_PLUGIN_DIR . 'admin/views/page-settings.php';
	}

	/**
	 * @return void
	 */
	public static function render_reports_page(): void {
		if ( ! current_user_can( 'publish_orgahb_contents' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'orgahb-manager' ) );
		}
		require_once ORGAHB_PLUGIN_DIR . 'admin/views/page-reports.php';
	}

	/**
	 * @return void
	 */
	public static function render_backup_export_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'orgahb-manager' ) );
		}
		require_once ORGAHB_PLUGIN_DIR . 'admin/views/page-backup-export.php';
	}
}
