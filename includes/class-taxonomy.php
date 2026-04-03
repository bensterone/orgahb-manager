<?php
/**
 * Registers the orgahb_section taxonomy.
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Registers the orgahb_section hierarchical taxonomy.
 *
 * Registered at init priority 5 — before CPTs at priority 10 — so CPTs can
 * declare themselves as attached to this taxonomy on registration.
 */
final class ORGAHB_Taxonomy {

	/**
	 * Hook registration — called from ORGAHB_Plugin::init_components().
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_all' ), 5 );
	}

	/**
	 * Registers all taxonomies. Called on the `init` hook at priority 5.
	 *
	 * @return void
	 */
	public static function register_all(): void {
		register_taxonomy(
			'orgahb_section',
			array( 'orgahb_page', 'orgahb_process', 'orgahb_document' ),
			array(
				'labels'            => array(
					'name'              => __( 'Sections', 'orgahb-manager' ),
					'singular_name'     => __( 'Section', 'orgahb-manager' ),
					'add_new_item'      => __( 'Add New Section', 'orgahb-manager' ),
					'edit_item'         => __( 'Edit Section', 'orgahb-manager' ),
					'new_item_name'     => __( 'New Section Name', 'orgahb-manager' ),
					'parent_item'       => __( 'Parent Section', 'orgahb-manager' ),
					'parent_item_colon' => __( 'Parent Section:', 'orgahb-manager' ),
					'search_items'      => __( 'Search Sections', 'orgahb-manager' ),
					'all_items'         => __( 'All Sections', 'orgahb-manager' ),
					'menu_name'         => __( 'Sections', 'orgahb-manager' ),
				),
				// Hierarchical: true — unlimited nesting, drives the SPA tree navigation.
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_in_rest'      => true,
				'rest_base'         => 'orgahb-sections',
				'show_admin_column' => true,
				'rewrite'           => array( 'slug' => 'handbook/section' ),
				'capabilities'      => array(
					'manage_terms' => 'manage_orgahb_sections',
					'edit_terms'   => 'manage_orgahb_sections',
					'delete_terms' => 'manage_orgahb_sections',
					// Editors can tag items but cannot create/delete sections.
					'assign_terms' => 'edit_orgahb_contents',
				),
			)
		);
	}
}
