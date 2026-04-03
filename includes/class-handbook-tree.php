<?php
/**
 * Desktop handbook tree view (spec §13.3).
 *
 * Registers the [orgahb_handbook] shortcode which renders the full-org
 * section-tree viewer — the desktop-first "read the handbook" entry point
 * complementing the mobile QR building-view.
 *
 * Layout: persistent left sidebar tree (orgahb_section hierarchy) +
 * inline content panel (pages, processes, documents).
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode and asset registration for the handbook tree viewer.
 */
final class ORGAHB_Handbook_Tree {

	// ── Hook registration ─────────────────────────────────────────────────────

	/** @return void */
	public static function init(): void {
		add_shortcode( 'orgahb_handbook', array( self::class, 'render_shortcode' ) );
	}

	// ── Shortcode ─────────────────────────────────────────────────────────────

	/**
	 * Renders the handbook tree mount point and enqueues the React bundle.
	 *
	 * Usage: [orgahb_handbook]
	 *
	 * @param array<string, string>|string $atts  Shortcode attributes (unused).
	 * @return string  HTML output.
	 */
	public static function render_shortcode( array|string $atts ): string {
		// Only render for authenticated users who can read content.
		if ( ! current_user_can( 'read_orgahb_content' ) ) {
			return '<p>' . esc_html__( 'You do not have permission to view the handbook.', 'orgahb-manager' ) . '</p>';
		}

		self::enqueue_assets();

		return '<div id="orgahb-handbook-tree" class="orgahb-ht-root"></div>'
			. '<noscript><p class="orgahb-ht-nojs">'
			. esc_html__( 'JavaScript is required to view the handbook.', 'orgahb-manager' )
			. '</p></noscript>';
	}

	// ── Asset enqueue ─────────────────────────────────────────────────────────

	/** @return void */
	private static function enqueue_assets(): void {
		$plugin_url = ORGAHB_PLUGIN_URL;
		$version    = ORGAHB_VERSION;
		$dist       = $plugin_url . 'assets/dist/';

		wp_enqueue_script(
			'orgahb-handbook-tree',
			$dist . 'handbook-tree.js',
			array(),
			$version,
			true
		);

		wp_enqueue_style(
			'orgahb-handbook-tree',
			$plugin_url . 'assets/css/handbook-tree.css',
			array(),
			$version
		);

		$current_user = wp_get_current_user();

		wp_localize_script(
			'orgahb-handbook-tree',
			'ORGAHB_TREE_VARS',
			array(
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'restUrl'     => esc_url_raw( rest_url( 'orgahb/v1' ) ),
				'orgName'     => get_bloginfo( 'name' ),
				'currentUser' => array(
					'id'         => $current_user->ID,
					'name'       => $current_user->display_name,
					'canAck'     => current_user_can( 'acknowledge_orgahb_content' ),
					'canLog'     => false, // execution requires building context; disabled in tree view
					'canObserve' => current_user_can( 'create_orgahb_observation' ),
					'canEdit'    => current_user_can( 'edit_orgahb_content' ),
				),
			)
		);
	}
}
