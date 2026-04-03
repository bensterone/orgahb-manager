<?php
/**
 * Registers custom roles and capabilities.
 *
 * Role hierarchy (lowest → highest):
 *   orgahb_reader → orgahb_operator → orgahb_editor → orgahb_reviewer → administrator
 *
 * Capability naming follows two patterns:
 *   - Semantic plugin caps (e.g. read_orgahb_content, approve_orgahb_content)
 *   - WordPress meta-mapped caps that WP derives for CPT CRUD:
 *       edit_orgahb_content / edit_orgahb_contents / edit_others_orgahb_contents
 *       publish_orgahb_contents / read_private_orgahb_contents
 *       delete_orgahb_content / delete_orgahb_contents  (admin only)
 *     And equivalents for orgahb_building:
 *       edit_orgahb_building / edit_orgahb_buildings / etc.
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Defines the five-role hierarchy and the full capability matrix.
 */
final class ORGAHB_Capabilities {

	/**
	 * Role definitions: slug → display name + capability map.
	 *
	 * Each role includes all caps it needs, including those inherited from
	 * lower-tier roles, so that each row is self-contained.
	 *
	 * @var array<string, array{display_name: string, caps: array<string, bool>}>
	 */
	private const ROLES = array(

		// ── Reader ────────────────────────────────────────────────────────────
		// Can read handbook content and acknowledge revisions.
		'orgahb_reader'   => array(
			'display_name' => 'Handbook Reader',
			'caps'         => array(
				'read'                       => true,
				'read_orgahb_content'        => true,
				'acknowledge_orgahb_content' => true,
			),
		),

		// ── Field Operator ────────────────────────────────────────────────────
		// Reads and acknowledges like a reader, plus can log process-step evidence.
		'orgahb_operator' => array(
			'display_name' => 'Field Operator',
			'caps'         => array(
				'read'                       => true,
				'read_orgahb_content'        => true,
				'acknowledge_orgahb_content' => true,
				'log_orgahb_process_step'    => true,
				'create_orgahb_observation'  => true,
			),
		),

		// ── Editor ────────────────────────────────────────────────────────────
		// Creates and manages content; cannot approve or publish.
		'orgahb_editor'   => array(
			'display_name' => 'Handbook Editor',
			'caps'         => array(
				'read'                          => true,
				'read_orgahb_content'           => true,
				'acknowledge_orgahb_content'    => true,
				'log_orgahb_process_step'       => true,
				'create_orgahb_observation'     => true,
				// Content authoring.
				'edit_orgahb_contents'          => true,
				'submit_orgahb_content'         => true,
				// WP meta-mapped caps required for the post editing UI.
				'edit_orgahb_content'           => true,
				'read_private_orgahb_contents'  => true,
				'upload_files'                  => true,
				// Building management (editors can draft building records).
				'edit_orgahb_buildings'         => true,
				'edit_orgahb_building'          => true,
			),
		),

		// ── Reviewer ──────────────────────────────────────────────────────────
		// Approves and publishes content; manages taxonomy and buildings.
		'orgahb_reviewer' => array(
			'display_name' => 'Handbook Reviewer',
			'caps'         => array(
				'read'                            => true,
				'read_orgahb_content'             => true,
				'acknowledge_orgahb_content'      => true,
				'log_orgahb_process_step'         => true,
				'create_orgahb_observation'       => true,
				// Content authoring.
				'edit_orgahb_contents'            => true,
				'submit_orgahb_content'           => true,
				'publish_orgahb_contents'         => true,
				'approve_orgahb_content'          => true,
				// Taxonomy management.
				'manage_orgahb_sections'          => true,
				// WP meta-mapped caps for content CPTs.
				'edit_orgahb_content'             => true,
				'read_private_orgahb_contents'    => true,
				'edit_others_orgahb_contents'     => true,
				'upload_files'                    => true,
				// Building management — full CRUD for reviewers.
				'manage_orgahb_buildings'         => true,
				'edit_orgahb_building'            => true,
				'edit_orgahb_buildings'           => true,
				'edit_others_orgahb_buildings'    => true,
				'publish_orgahb_buildings'        => true,
				'read_private_orgahb_buildings'   => true,
			),
		),
	);

	/**
	 * Returns the union of all caps across all roles, plus administrator-only caps.
	 *
	 * @return array<string, bool>
	 */
	private static function all_caps(): array {
		$caps = array();
		foreach ( self::ROLES as $role_data ) {
			foreach ( $role_data['caps'] as $cap => $granted ) {
				$caps[ $cap ] = $granted;
			}
		}

		// Administrator-only destructive caps.
		$caps['delete_orgahb_contents']  = true;
		$caps['delete_orgahb_content']   = true;
		$caps['delete_orgahb_buildings'] = true;
		$caps['delete_orgahb_building']  = true;

		return $caps;
	}

	/**
	 * Creates (or recreates) the four custom roles and syncs all caps to administrator.
	 *
	 * Safe to call repeatedly — remove_role() + add_role() replaces stale cap sets
	 * on plugin updates without leaving orphaned capabilities.
	 *
	 * @return void
	 */
	public static function register_roles(): void {
		foreach ( self::ROLES as $slug => $role_data ) {
			remove_role( $slug );
			add_role( $slug, $role_data['display_name'], $role_data['caps'] );
		}

		// Sync every custom cap to the administrator role.
		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			foreach ( self::all_caps() as $cap => $granted ) {
				$administrator->add_cap( $cap, $granted );
			}
		}
	}

	/**
	 * Removes the four custom roles and strips custom caps from administrator.
	 *
	 * Called by ORGAHB_Install::uninstall().
	 *
	 * @return void
	 */
	public static function remove_roles(): void {
		foreach ( array_keys( self::ROLES ) as $slug ) {
			remove_role( $slug );
		}

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			foreach ( array_keys( self::all_caps() ) as $cap ) {
				$administrator->remove_cap( $cap );
			}
		}
	}
}
