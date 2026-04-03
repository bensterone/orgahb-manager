<?php
/**
 * Building record helpers and area definitions.
 *
 * Provides typed getters/setters for all building post-meta fields
 * defined in spec §16.4, plus area management (§15.4 / §16.5).
 *
 * Meta keys are kept as class constants so the rest of the codebase
 * never hard-codes raw strings.
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Building meta helpers, area management, and lifecycle hooks.
 */
final class ORGAHB_Buildings {

	// ── Meta key constants (spec §16.1 + §16.4) ───────────────────────────────

	// Shared content meta (also used on pages / processes / documents).
	const META_OWNER_USER_ID  = '_orgahb_owner_user_id';
	const META_DEPUTY_USER_ID = '_orgahb_deputy_user_id';
	const META_OWNER_LABEL    = '_orgahb_owner_label';
	const META_NEXT_REVIEW    = '_orgahb_next_review';
	const META_SEARCH_ALIASES = '_orgahb_search_aliases';

	// Building-specific meta.
	const META_CODE           = '_orgahb_building_code';
	const META_ADDRESS        = '_orgahb_building_address';
	const META_CONTACTS       = '_orgahb_building_contacts';
	const META_EMERGENCY      = '_orgahb_emergency_notes';
	const META_AREAS          = '_orgahb_areas_json';
	const META_ACTIVE         = '_orgahb_building_active';

	// ── Hook registration ─────────────────────────────────────────────────────

	/**
	 * Hook registration — called from ORGAHB_Plugin::init_components().
	 *
	 * @return void
	 */
	public static function init(): void {
		// Ensure every building gets a QR token the moment it is first saved.
		add_action( 'save_post_orgahb_building', array( self::class, 'on_save' ), 20, 1 );

		// Clean up bundle links when a building or content item is permanently deleted.
		add_action( 'before_delete_post', array( self::class, 'on_delete' ), 10, 1 );
	}

	/**
	 * Fires after a building post is saved.
	 *
	 * Ensures the QR token exists and that at least one area (general) is
	 * defined.
	 *
	 * @param int $post_id
	 * @return void
	 */
	public static function on_save( int $post_id ): void {
		// Skip revisions and autosaves.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Ensure immutable QR token.
		ORGAHB_QR::ensure_token( $post_id );

		// Ensure areas JSON has at least a 'general' entry.
		$raw = get_post_meta( $post_id, self::META_AREAS, true );
		if ( '' === $raw || ! $raw ) {
			update_post_meta(
				$post_id,
				self::META_AREAS,
				wp_json_encode( self::default_areas() )
			);
		}
	}

	/**
	 * Fires before a post is permanently deleted.
	 *
	 * - For buildings: removes all bundle links for that building.
	 * - For content CPTs (page/process/document): removes all bundle links that
	 *   reference the deleted content item across every building.
	 *
	 * @param int $post_id
	 * @return void
	 */
	public static function on_delete( int $post_id ): void {
		$type = get_post_type( $post_id );

		if ( 'orgahb_building' === $type ) {
			ORGAHB_Building_Links::remove_for_building( $post_id );
			return;
		}

		// Cascade-delete bundle links for deleted content items.
		$content_type_map = array_flip( ORGAHB_Building_Links::CPT_MAP );
		if ( isset( $content_type_map[ $type ] ) ) {
			ORGAHB_Building_Links::remove_for_content( $content_type_map[ $type ], $post_id );
		}
	}

	// ── Area management ───────────────────────────────────────────────────────

	/**
	 * Returns the area definitions for a building, guaranteeing that the
	 * 'general' area always exists (spec §15.4).
	 *
	 * Each area element shape:
	 *   [ 'key' => string, 'label' => string, 'sort_order' => int, 'description' => string ]
	 *
	 * @param int $building_id
	 * @return list<array<string, mixed>>
	 */
	public static function get_areas( int $building_id ): array {
		$raw = get_post_meta( $building_id, self::META_AREAS, true );
		if ( ! $raw ) {
			return self::default_areas();
		}

		$areas = json_decode( $raw, true );
		return is_array( $areas ) ? self::ensure_general_area( $areas ) : self::default_areas();
	}

	/**
	 * Persists area definitions for a building.
	 *
	 * Sanitizes each area, enforces the 'general' area, and re-indexes sort_order.
	 *
	 * @param int                        $building_id
	 * @param list<array<string, mixed>> $areas
	 * @return void
	 */
	public static function save_areas( int $building_id, array $areas ): void {
		$areas = self::ensure_general_area( $areas );
		$areas = self::sanitize_areas( $areas );

		update_post_meta(
			$building_id,
			self::META_AREAS,
			wp_json_encode( $areas )
		);
	}

	/**
	 * Returns the default area set — just 'general'.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function default_areas(): array {
		return array(
			array(
				'key'         => 'general',
				'label'       => __( 'General', 'orgahb-manager' ),
				'sort_order'  => 0,
				'description' => '',
			),
		);
	}

	// ── Meta getters / setters ────────────────────────────────────────────────

	/** @return string */
	public static function get_code( int $id ): string {
		return (string) get_post_meta( $id, self::META_CODE, true );
	}

	/** @return string */
	public static function get_address( int $id ): string {
		return (string) get_post_meta( $id, self::META_ADDRESS, true );
	}

	/** @return string */
	public static function get_contacts( int $id ): string {
		return (string) get_post_meta( $id, self::META_CONTACTS, true );
	}

	/** @return string */
	public static function get_emergency_notes( int $id ): string {
		return (string) get_post_meta( $id, self::META_EMERGENCY, true );
	}

	/** @return bool  True if active (defaults to true when meta not set). */
	public static function is_active( int $id ): bool {
		$val = get_post_meta( $id, self::META_ACTIVE, true );
		return '' === $val ? true : (bool) $val;
	}

	/** @return int|null */
	public static function get_owner_user_id( int $id ): ?int {
		$val = get_post_meta( $id, self::META_OWNER_USER_ID, true );
		return '' !== $val ? (int) $val : null;
	}

	/** @return string  Next review date as Y-m-d, or empty string. */
	public static function get_next_review( int $id ): string {
		return (string) get_post_meta( $id, self::META_NEXT_REVIEW, true );
	}

	/**
	 * Persists the sanitized building meta fields in a single call.
	 *
	 * Only the keys present in $data are updated; omitted keys are left unchanged.
	 *
	 * @param int                  $building_id
	 * @param array<string, mixed> $data
	 * @return void
	 */
	public static function save_meta( int $building_id, array $data ): void {
		$map = array(
			'code'           => array( self::META_CODE,       'sanitize_text_field' ),
			'address'        => array( self::META_ADDRESS,    'sanitize_textarea_field' ),
			'contacts'       => array( self::META_CONTACTS,   'sanitize_textarea_field' ),
			'emergency'      => array( self::META_EMERGENCY,  'sanitize_textarea_field' ),
			'active'         => array( self::META_ACTIVE,     'boolval' ),
			'owner_user_id'  => array( self::META_OWNER_USER_ID,  'absint' ),
			'deputy_user_id' => array( self::META_DEPUTY_USER_ID, 'absint' ),
			'owner_label'    => array( self::META_OWNER_LABEL,    'sanitize_text_field' ),
			'next_review'    => array( self::META_NEXT_REVIEW,    'sanitize_text_field' ),
			'search_aliases' => array( self::META_SEARCH_ALIASES, 'sanitize_text_field' ),
		);

		foreach ( $map as $key => $spec ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			[ $meta_key, $sanitizer ] = $spec;
			update_post_meta( $building_id, $meta_key, $sanitizer( $data[ $key ] ) );
		}
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Ensures the 'general' area is present in the areas array.
	 * Prepends it if missing; does not duplicate it.
	 *
	 * @param list<array<string, mixed>> $areas
	 * @return list<array<string, mixed>>
	 */
	private static function ensure_general_area( array $areas ): array {
		foreach ( $areas as $area ) {
			if ( ( $area['key'] ?? '' ) === 'general' ) {
				return $areas;
			}
		}

		array_unshift(
			$areas,
			array(
				'key'         => 'general',
				'label'       => __( 'General', 'orgahb-manager' ),
				'sort_order'  => 0,
				'description' => '',
			)
		);

		return $areas;
	}

	/**
	 * Sanitizes a raw areas array — normalises keys and trims values.
	 *
	 * @param list<array<string, mixed>> $areas
	 * @return list<array<string, mixed>>
	 */
	private static function sanitize_areas( array $areas ): array {
		$sanitized = array();
		$order     = 0;

		foreach ( $areas as $area ) {
			$key = sanitize_key( $area['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}

			$sanitized[] = array(
				'key'         => $key,
				'label'       => sanitize_text_field( $area['label'] ?? $key ),
				'sort_order'  => isset( $area['sort_order'] ) ? (int) $area['sort_order'] : $order,
				'description' => sanitize_textarea_field( $area['description'] ?? '' ),
			);

			++$order;
		}

		return array_values( $sanitized );
	}
}
