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
				'read'                               => true,
				'read_orgahb_content'                => true,
				'acknowledge_orgahb_content'         => true,
				'log_orgahb_process_step'            => true,
				'create_orgahb_observation'          => true,
				// Content authoring.
				'edit_orgahb_contents'               => true,
				'create_orgahb_contents'             => true,
				'submit_orgahb_content'              => true,
				// WP meta-mapped caps required for the post editing UI.
				'edit_orgahb_content'                => true,
				'read_private_orgahb_contents'       => true,
				'edit_published_orgahb_contents'     => true,
				'edit_private_orgahb_contents'       => true,
				'upload_files'                       => true,
				// Building management (editors can manage bundles and draft buildings).
				'manage_orgahb_buildings'            => true,
				'edit_orgahb_buildings'              => true,
				'edit_orgahb_building'               => true,
				'create_orgahb_buildings'            => true,
				'edit_published_orgahb_buildings'    => true,
				'edit_private_orgahb_buildings'      => true,
			),
		),

		// ── Reviewer ──────────────────────────────────────────────────────────
		// Approves and publishes content; manages taxonomy and buildings.
		'orgahb_reviewer' => array(
			'display_name' => 'Handbook Reviewer',
			'caps'         => array(
				'read'                                 => true,
				'read_orgahb_content'                  => true,
				'acknowledge_orgahb_content'           => true,
				'log_orgahb_process_step'              => true,
				'create_orgahb_observation'            => true,
				// Content authoring.
				'edit_orgahb_contents'                 => true,
				'create_orgahb_contents'               => true,
				'submit_orgahb_content'                => true,
				'publish_orgahb_contents'              => true,
				'approve_orgahb_content'               => true,
				// Taxonomy management.
				'manage_orgahb_sections'               => true,
				// WP meta-mapped caps for content CPTs.
				'edit_orgahb_content'                  => true,
				'read_private_orgahb_contents'         => true,
				'edit_others_orgahb_contents'          => true,
				'edit_published_orgahb_contents'       => true,
				'edit_private_orgahb_contents'         => true,
				'delete_published_orgahb_contents'     => true,
				'delete_private_orgahb_contents'       => true,
				'delete_others_orgahb_contents'        => true,
				'upload_files'                         => true,
				// Building management — full CRUD for reviewers.
				'manage_orgahb_buildings'              => true,
				'edit_orgahb_building'                 => true,
				'edit_orgahb_buildings'                => true,
				'create_orgahb_buildings'              => true,
				'edit_others_orgahb_buildings'         => true,
				'edit_published_orgahb_buildings'      => true,
				'edit_private_orgahb_buildings'        => true,
				'publish_orgahb_buildings'             => true,
				'read_private_orgahb_buildings'        => true,
				'delete_published_orgahb_buildings'    => true,
				'delete_private_orgahb_buildings'      => true,
				'delete_others_orgahb_buildings'       => true,
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

		// Additional meta-mapped caps WordPress derives from capability_type.
		$caps['create_orgahb_contents']          = true;
		$caps['create_orgahb_buildings']         = true;
		$caps['edit_private_orgahb_contents']    = true;
		$caps['edit_published_orgahb_contents']  = true;
		$caps['delete_private_orgahb_contents']  = true;
		$caps['delete_published_orgahb_contents'] = true;
		$caps['delete_others_orgahb_contents']   = true;
		$caps['edit_private_orgahb_buildings']   = true;
		$caps['edit_published_orgahb_buildings'] = true;
		$caps['delete_private_orgahb_buildings'] = true;
		$caps['delete_published_orgahb_buildings'] = true;
		$caps['delete_others_orgahb_buildings']  = true;

		// Administrator-only destructive caps.
		$caps['delete_orgahb_contents']  = true;
		$caps['delete_orgahb_content']   = true;
		$caps['delete_orgahb_buildings'] = true;
		$caps['delete_orgahb_building']  = true;

		return $caps;
	}

	/**
	 * All orgahb primitive caps — used by the map_meta_cap filter.
	 *
	 * @var string[]|null
	 */
	private static ?array $orgahb_cap_keys = null;

	/**
	 * Registers both cap filters.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'map_meta_cap',  array( self::class, 'map_orgahb_caps' ), 1, 4 );
		add_filter( 'user_has_cap',  array( self::class, 'grant_caps_filter' ), 1, 4 );
	}

	/**
	 * Maps any orgahb cap to `manage_options` for administrators.
	 *
	 * When WordPress checks e.g. `current_user_can('edit_orgahb_contents')`:
	 *  1. map_meta_cap fires — if user is admin, we return ['manage_options'].
	 *  2. WordPress then checks if `manage_options` is in allcaps — it is for admins.
	 *  3. Access granted without touching role storage at all.
	 *
	 * For non-admins the original $caps array is returned unchanged, so our
	 * custom roles continue to work via the standard user_has_cap path.
	 *
	 * @param string[] $caps    Primitive caps required.
	 * @param string   $cap     The cap being checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Additional args.
	 * @return string[]
	 */
	public static function map_orgahb_caps( array $caps, string $cap, int $user_id, array $args ): array {
		// Build the cap key list once.
		if ( null === self::$orgahb_cap_keys ) {
			self::$orgahb_cap_keys = array_keys( self::all_caps() );
		}

		if ( ! in_array( $cap, self::$orgahb_cap_keys, true ) ) {
			return $caps;
		}

		$user = get_userdata( $user_id );
		if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) {
			// Map to a cap the administrator role always has.
			return array( 'manage_options' );
		}

		return $caps;
	}

	/**
	 * Dynamically grants all orgahb capabilities to any user with manage_options.
	 *
	 * Belt-and-suspenders alongside map_orgahb_caps.
	 *
	 * @param bool[]   $allcaps All caps currently assigned to the user.
	 * @param string[] $caps    Primitive caps being checked.
	 * @param array    $args    Check arguments.
	 * @param mixed    $user    The user object.
	 * @return bool[]
	 */
	public static function grant_caps_filter( array $allcaps, array $caps, array $args, $user ): array {
		$is_admin = ! empty( $allcaps['manage_options'] )
			|| ( $user instanceof WP_User && in_array( 'administrator', (array) $user->roles, true ) );

		if ( $is_admin ) {
			foreach ( self::all_caps() as $cap => $granted ) {
				$allcaps[ $cap ] = $granted;
			}
		}

		return $allcaps;
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
