<?php
/**
 * Building bundle relation table — CRUD for building-to-content links.
 *
 * The building bundle is the curated set of pages, processes, and documents
 * linked to one building (spec §15).
 *
 * Each link ties: building_id + area_key + content_type + content_id.
 * Optional: sort_order, is_featured, local_note, advisory_interval_label.
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Building bundle link CRUD helper.
 */
final class ORGAHB_Building_Links {

	/** Allowed content_type values. */
	const CONTENT_TYPES = array( 'page', 'process', 'document' );

	/** Map from content_type → actual CPT slug. */
	const CPT_MAP = array(
		'page'     => 'orgahb_page',
		'process'  => 'orgahb_process',
		'document' => 'orgahb_document',
	);

	// ── Write ─────────────────────────────────────────────────────────────────

	/**
	 * Adds (or updates if duplicate) a building bundle link.
	 *
	 * The UNIQUE KEY on (building_id, area_key, content_type, content_id)
	 * means this uses REPLACE semantics — duplicate links are updated in place.
	 *
	 * @param int    $building_id
	 * @param string $area_key      Area slug (e.g. 'general', 'heating'). Defaults to 'general'.
	 * @param string $content_type  One of 'page', 'process', 'document'.
	 * @param int    $content_id    Post ID of the linked content item.
	 * @param array  $opts {
	 *     @type int    $sort_order              Display order within the area.
	 *     @type bool   $is_featured             Pin this item to the featured section.
	 *     @type string $local_note              Building-local annotation.
	 *     @type string $advisory_interval_label Informational interval hint (spec §15.6).
	 * }
	 * @return int  Inserted/updated row ID, or 0 on failure.
	 */
	public static function add(
		int    $building_id,
		string $area_key,
		string $content_type,
		int    $content_id,
		array  $opts = []
	): int {
		global $wpdb;

		if ( ! in_array( $content_type, self::CONTENT_TYPES, true ) ) {
			return 0;
		}

		$area_key = sanitize_key( $area_key ) ?: 'general';

		$result = $wpdb->replace(
			$wpdb->prefix . 'orgahb_building_links',
			array(
				'building_id'             => $building_id,
				'area_key'                => $area_key,
				'content_type'            => $content_type,
				'content_id'              => $content_id,
				'sort_order'              => isset( $opts['sort_order'] ) ? (int) $opts['sort_order'] : 0,
				'is_featured'             => isset( $opts['is_featured'] ) ? (int) (bool) $opts['is_featured'] : 0,
				'local_note'              => isset( $opts['local_note'] )
					? sanitize_text_field( $opts['local_note'] )
					: null,
				'advisory_interval_label' => isset( $opts['advisory_interval_label'] )
					? sanitize_text_field( $opts['advisory_interval_label'] )
					: null,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		$inserted = $result ? (int) $wpdb->insert_id : 0;
		if ( $inserted ) {
			do_action( 'orgahb_building_links_changed', $building_id );
		}
		return $inserted;
	}

	/**
	 * Removes a single link by its primary key.
	 *
	 * @param int $link_id
	 * @return bool
	 */
	public static function remove( int $link_id ): bool {
		global $wpdb;

		// Fetch building_id before deletion so we can fire the cache-bust action.
		$building_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT building_id FROM {$wpdb->prefix}orgahb_building_links WHERE id = %d",
				$link_id
			)
		);

		$deleted = (bool) $wpdb->delete(
			$wpdb->prefix . 'orgahb_building_links',
			array( 'id' => $link_id ),
			array( '%d' )
		);

		if ( $deleted && $building_id ) {
			do_action( 'orgahb_building_links_changed', $building_id );
		}

		return $deleted;
	}

	/**
	 * Removes all bundle links for a building.
	 * Called on building deletion.
	 *
	 * @param int $building_id
	 * @return void
	 */
	public static function remove_for_building( int $building_id ): void {
		global $wpdb;
		$wpdb->delete(
			$wpdb->prefix . 'orgahb_building_links',
			array( 'building_id' => $building_id ),
			array( '%d' )
		);
		do_action( 'orgahb_building_links_changed', $building_id );
	}

	/**
	 * Removes all links pointing to a specific content item across all buildings.
	 * Useful when a page/process/document is permanently deleted.
	 *
	 * @param string $content_type  'page', 'process', or 'document'.
	 * @param int    $content_id
	 * @return void
	 */
	public static function remove_for_content( string $content_type, int $content_id ): void {
		global $wpdb;
		$wpdb->delete(
			$wpdb->prefix . 'orgahb_building_links',
			array( 'content_type' => $content_type, 'content_id' => $content_id ),
			array( '%s', '%d' )
		);
	}

	/**
	 * Updates mutable fields on an existing link row.
	 *
	 * @param int   $link_id
	 * @param array $data  Keys: sort_order, is_featured, local_note, advisory_interval_label.
	 * @return bool
	 */
	public static function update( int $link_id, array $data ): bool {
		global $wpdb;

		$update = array();
		$format = array();

		if ( array_key_exists( 'sort_order', $data ) ) {
			$update['sort_order'] = (int) $data['sort_order'];
			$format[]             = '%d';
		}
		if ( array_key_exists( 'is_featured', $data ) ) {
			$update['is_featured'] = (int) (bool) $data['is_featured'];
			$format[]              = '%d';
		}
		if ( array_key_exists( 'local_note', $data ) ) {
			$update['local_note'] = sanitize_text_field( (string) $data['local_note'] );
			$format[]             = '%s';
		}
		if ( array_key_exists( 'advisory_interval_label', $data ) ) {
			$update['advisory_interval_label'] = sanitize_text_field( (string) $data['advisory_interval_label'] );
			$format[]                          = '%s';
		}

		if ( empty( $update ) ) {
			return false;
		}

		// Fetch building_id before update so we can fire the cache-bust action.
		$building_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT building_id FROM {$wpdb->prefix}orgahb_building_links WHERE id = %d",
				$link_id
			)
		);

		$updated = false !== $wpdb->update(
			$wpdb->prefix . 'orgahb_building_links',
			$update,
			array( 'id' => $link_id ),
			$format,
			array( '%d' )
		);

		if ( $updated && $building_id ) {
			do_action( 'orgahb_building_links_changed', $building_id );
		}

		return $updated;
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * Returns all bundle links for a building, optionally filtered by area.
	 * Ordered by area_key ASC, sort_order ASC.
	 *
	 * @param int         $building_id
	 * @param string|null $area_key  Omit to return all areas.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_building( int $building_id, ?string $area_key = null ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'orgahb_building_links';

		if ( null !== $area_key ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					 WHERE building_id = %d AND area_key = %s
					 ORDER BY sort_order ASC",
					$building_id,
					sanitize_key( $area_key )
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					 WHERE building_id = %d
					 ORDER BY area_key ASC, sort_order ASC",
					$building_id
				),
				ARRAY_A
			);
		}

		return $rows ?: array();
	}

	/**
	 * Returns featured links for a building across all areas, ordered by sort_order.
	 *
	 * @param int $building_id
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_featured( int $building_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'orgahb_building_links';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE building_id = %d AND is_featured = 1
				 ORDER BY sort_order ASC",
				$building_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Returns all buildings that reference a given content item.
	 *
	 * @param string $content_type
	 * @param int    $content_id
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_buildings_for_content( string $content_type, int $content_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'orgahb_building_links';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE content_type = %s AND content_id = %d
				 ORDER BY building_id ASC",
				$content_type,
				$content_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Returns a flat list of building_id values that link to a given content post,
	 * regardless of content type. Used for cache invalidation.
	 *
	 * @param int $content_id  WP post ID of the content item.
	 * @return int[]
	 */
	public static function get_buildings_for_content_by_post_id( int $content_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'orgahb_building_links';

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT building_id FROM {$table} WHERE content_id = %d",
				$content_id
			)
		);

		return array_map( 'intval', $rows ?: array() );
	}
}
