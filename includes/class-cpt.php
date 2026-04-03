<?php
/**
 * Registers all four custom post types.
 *
 * CPTs registered here:
 *   orgahb_page     — narrative handbook pages (Gutenberg)
 *   orgahb_process  — process diagrams + hotspot JSON (meta-box driven)
 *   orgahb_document — controlled documents (PDF / DOCX file pointer)
 *   orgahb_building — building record (areas, QR token, contacts — all in meta)
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Registers orgahb_page, orgahb_process, orgahb_document, and orgahb_building.
 */
final class ORGAHB_CPT {

	/**
	 * Hook registration — called from ORGAHB_Plugin::init_components().
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_all' ), 10 );

		// QR scan route: ^b/{uuid} → query var orgahb_qr_token.
		// Priority 11 so CPTs are already registered when the rule is added.
		add_action( 'init', array( self::class, 'register_qr_route' ), 11 );
		add_filter( 'query_vars', array( self::class, 'add_query_vars' ) );
		add_filter( 'template_include', array( self::class, 'load_building_template' ) );
	}

	/**
	 * Registers the rewrite rule that maps the short QR URL to the template.
	 *
	 * Pattern: {site}/b/{uuid36}
	 * The token is 36 chars: 32 hex + 4 hyphens (UUID v4).
	 *
	 * @return void
	 */
	public static function register_qr_route(): void {
		add_rewrite_rule(
			'^b/([a-zA-Z0-9\-]{36})/?$',
			'index.php?orgahb_qr_token=$matches[1]',
			'top'
		);
	}

	/**
	 * Exposes orgahb_qr_token as a recognised WordPress query variable.
	 *
	 * @param string[] $vars
	 * @return string[]
	 */
	public static function add_query_vars( array $vars ): array {
		$vars[] = 'orgahb_qr_token';
		return $vars;
	}

	/**
	 * Loads the building-view template when a QR token query var is present.
	 *
	 * @param string $template
	 * @return string
	 */
	public static function load_building_template( string $template ): string {
		if ( '' === get_query_var( 'orgahb_qr_token', '' ) ) {
			return $template;
		}
		$custom = ORGAHB_PLUGIN_DIR . 'templates/building-view.php';
		return file_exists( $custom ) ? $custom : $template;
	}

	/**
	 * Registers all CPTs. Called on the `init` hook at priority 10.
	 *
	 * @return void
	 */
	public static function register_all(): void {
		self::register_page();
		self::register_process();
		self::register_document();
		self::register_building();
	}

	// ── Private registration methods ──────────────────────────────────────────

	/**
	 * Handbook pages — Gutenberg-authored narrative content.
	 *
	 * @return void
	 */
	private static function register_page(): void {
		register_post_type(
			'orgahb_page',
			array(
				'labels'             => array(
					'name'               => __( 'Handbook Pages', 'orgahb-manager' ),
					'singular_name'      => __( 'Handbook Page', 'orgahb-manager' ),
					'add_new'            => __( 'Add New Page', 'orgahb-manager' ),
					'add_new_item'       => __( 'Add New Handbook Page', 'orgahb-manager' ),
					'edit_item'          => __( 'Edit Handbook Page', 'orgahb-manager' ),
					'new_item'           => __( 'New Handbook Page', 'orgahb-manager' ),
					'view_item'          => __( 'View Handbook Page', 'orgahb-manager' ),
					'search_items'       => __( 'Search Handbook Pages', 'orgahb-manager' ),
					'not_found'          => __( 'No handbook pages found.', 'orgahb-manager' ),
					'not_found_in_trash' => __( 'No handbook pages found in trash.', 'orgahb-manager' ),
					'menu_name'          => __( 'Handbook Pages', 'orgahb-manager' ),
				),
				'public'             => false,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => 'orgahb-handbook',
				'show_in_rest'       => true,
				'rest_base'          => 'orgahb-pages',
				'hierarchical'       => false,
				'supports'           => array( 'title', 'editor', 'revisions', 'custom-fields', 'author' ),
				'taxonomies'         => array( 'orgahb_section' ),
				'has_archive'        => false,
				'rewrite'            => array( 'slug' => 'handbook/page' ),
				'capability_type'    => array( 'orgahb_content', 'orgahb_contents' ),
				'map_meta_cap'       => true,
			)
		);
	}

	/**
	 * Process diagrams — visual asset + hotspot JSON overlay.
	 * No Gutenberg editor; all data lives in meta boxes.
	 *
	 * @return void
	 */
	private static function register_process(): void {
		register_post_type(
			'orgahb_process',
			array(
				'labels'             => array(
					'name'               => __( 'Processes', 'orgahb-manager' ),
					'singular_name'      => __( 'Process', 'orgahb-manager' ),
					'add_new'            => __( 'Add New Process', 'orgahb-manager' ),
					'add_new_item'       => __( 'Add New Process', 'orgahb-manager' ),
					'edit_item'          => __( 'Edit Process', 'orgahb-manager' ),
					'new_item'           => __( 'New Process', 'orgahb-manager' ),
					'view_item'          => __( 'View Process', 'orgahb-manager' ),
					'search_items'       => __( 'Search Processes', 'orgahb-manager' ),
					'not_found'          => __( 'No processes found.', 'orgahb-manager' ),
					'not_found_in_trash' => __( 'No processes found in trash.', 'orgahb-manager' ),
					'menu_name'          => __( 'Processes', 'orgahb-manager' ),
				),
				'public'             => false,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => 'orgahb-handbook',
				'show_in_rest'       => true,
				'rest_base'          => 'orgahb-processes',
				'hierarchical'       => false,
				// No 'editor' — process edit screen uses meta boxes only.
				'supports'           => array( 'title', 'revisions', 'custom-fields', 'author' ),
				'taxonomies'         => array( 'orgahb_section' ),
				'has_archive'        => false,
				'capability_type'    => array( 'orgahb_content', 'orgahb_contents' ),
				'map_meta_cap'       => true,
			)
		);
	}

	/**
	 * Controlled documents — file pointer (PDF / DOCX), version metadata,
	 * and optional acknowledgment gate.
	 *
	 * @return void
	 */
	private static function register_document(): void {
		register_post_type(
			'orgahb_document',
			array(
				'labels'             => array(
					'name'               => __( 'Documents', 'orgahb-manager' ),
					'singular_name'      => __( 'Document', 'orgahb-manager' ),
					'add_new'            => __( 'Add New Document', 'orgahb-manager' ),
					'add_new_item'       => __( 'Add New Document', 'orgahb-manager' ),
					'edit_item'          => __( 'Edit Document', 'orgahb-manager' ),
					'new_item'           => __( 'New Document', 'orgahb-manager' ),
					'view_item'          => __( 'View Document', 'orgahb-manager' ),
					'search_items'       => __( 'Search Documents', 'orgahb-manager' ),
					'not_found'          => __( 'No documents found.', 'orgahb-manager' ),
					'not_found_in_trash' => __( 'No documents found in trash.', 'orgahb-manager' ),
					'menu_name'          => __( 'Documents', 'orgahb-manager' ),
				),
				'public'             => false,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => 'orgahb-handbook',
				'show_in_rest'       => true,
				'rest_base'          => 'orgahb-documents',
				'hierarchical'       => false,
				'supports'           => array( 'title', 'revisions', 'custom-fields', 'author' ),
				'taxonomies'         => array( 'orgahb_section' ),
				'has_archive'        => false,
				'capability_type'    => array( 'orgahb_content', 'orgahb_contents' ),
				'map_meta_cap'       => true,
			)
		);
	}

	/**
	 * Building records — the primary physical anchor object.
	 *
	 * All building data (address, QR token, areas, contacts, emergency notes)
	 * is stored in post meta, not in the WP editor. No Gutenberg editor here.
	 *
	 * Buildings are not hierarchical — areas are structured metadata, not
	 * separate posts (per spec §14.6).
	 *
	 * @return void
	 */
	private static function register_building(): void {
		register_post_type(
			'orgahb_building',
			array(
				'labels'             => array(
					'name'               => __( 'Buildings', 'orgahb-manager' ),
					'singular_name'      => __( 'Building', 'orgahb-manager' ),
					'add_new'            => __( 'Add New Building', 'orgahb-manager' ),
					'add_new_item'       => __( 'Add New Building', 'orgahb-manager' ),
					'edit_item'          => __( 'Edit Building', 'orgahb-manager' ),
					'new_item'           => __( 'New Building', 'orgahb-manager' ),
					'view_item'          => __( 'View Building', 'orgahb-manager' ),
					'search_items'       => __( 'Search Buildings', 'orgahb-manager' ),
					'not_found'          => __( 'No buildings found.', 'orgahb-manager' ),
					'not_found_in_trash' => __( 'No buildings found in trash.', 'orgahb-manager' ),
					'menu_name'          => __( 'Buildings', 'orgahb-manager' ),
				),
				'public'             => false,
				// publicly_queryable needed so the QR landing route can resolve
				// the building slug / token without exposing it in the main feed.
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => 'orgahb-handbook',
				'show_in_rest'       => true,
				'rest_base'          => 'orgahb-buildings',
				// Not hierarchical — areas are stored as _orgahb_areas_json meta,
				// not as child posts (spec §14.6).
				'hierarchical'       => false,
				// No 'editor' — all building detail is in meta boxes.
				'supports'           => array( 'title', 'custom-fields', 'author' ),
				'has_archive'        => false,
				'rewrite'            => array( 'slug' => 'handbook/building', 'with_front' => false ),
				'capability_type'    => array( 'orgahb_building', 'orgahb_buildings' ),
				'map_meta_cap'       => true,
			)
		);
	}
}
