<?php
/**
 * Field evidence logging for process step executions.
 *
 * When a field operator taps a hotspot on a process diagram and logs an
 * outcome, this class writes an immutable execution row (spec §7.4, §18.2).
 *
 * Rows are never updated — every interaction is a new append.
 * Supports an offline queue_uuid column for deferred offline sync (spec §19.6).
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Process step execution (field evidence) writer and query helper.
 */
final class ORGAHB_Executions {

	/** Valid outcome values (spec §7.4). */
	const OUTCOMES = array(
		'completed',
		'issue_noted',
		'blocked',
		'not_applicable',
		'escalated',
	);

	// ── Write ─────────────────────────────────────────────────────────────────

	/**
	 * Records one field evidence event.
	 *
	 * @param array $data {
	 *     Required:
	 *     @type int    $post_id          orgahb_process post ID.
	 *     @type string $hotspot_id       Stable hotspot identifier from hotspot JSON.
	 *     @type int    $building_id      Building where the step was executed.
	 *     @type string $outcome          One of self::OUTCOMES.
	 *     @type int    $post_revision_id WP revision ID current at execution time.
	 *
	 *     Optional:
	 *     @type int    $user_id               Acting user (nullable for anonymous/kiosk use).
	 *     @type string $area_key              Building area slug.
	 *     @type string $note                  Free-text operator note.
	 *     @type string $post_version_label    Human-readable version label.
	 *     @type string $executed_at           UTC datetime override (Y-m-d H:i:s[.u]).
	 *     @type string $queue_uuid            Client-generated UUID for offline dedup.
	 *     @type string $source                'ui' or 'api'. Defaults to 'ui'.
	 * }
	 * @return int  Inserted row ID, or 0 on validation/DB failure.
	 */
	public static function record( array $data ): int {
		global $wpdb;

		if ( ! in_array( $data['outcome'] ?? '', self::OUTCOMES, true ) ) {
			return 0;
		}

		$user_id = isset( $data['user_id'] ) ? (int) $data['user_id'] : null;
		$user    = $user_id ? get_userdata( $user_id ) : null;

		$dt          = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$executed_at = $data['executed_at'] ?? $dt->format( 'Y-m-d H:i:s.u' );

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'orgahb_executions',
			array(
				'post_id'               => (int) $data['post_id'],
				'hotspot_id'            => sanitize_text_field( $data['hotspot_id'] ),
				'building_id'           => (int) $data['building_id'],
				'area_key'              => isset( $data['area_key'] ) ? sanitize_key( $data['area_key'] ) : null,
				'user_id'               => $user_id,
				'historical_user_label' => $user
					? $user->display_name
					: ( isset( $data['historical_user_label'] ) ? (string) $data['historical_user_label'] : null ),
				'outcome'               => $data['outcome'],
				'executed_at'           => $executed_at,
				'note'                  => isset( $data['note'] )
					? sanitize_textarea_field( $data['note'] )
					: null,
				'post_revision_id'      => (int) ( $data['post_revision_id'] ?? 0 ),
				'post_version_label'    => $data['post_version_label'] ?? null,
				'queue_uuid'            => $data['queue_uuid'] ?? null,
				'source'                => $data['source'] ?? 'ui',
			),
			array( '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	// ── Query ─────────────────────────────────────────────────────────────────

	/**
	 * Returns execution rows for a building.
	 *
	 * @param int   $building_id
	 * @param array $args {
	 *     @type string $area_key   Filter to a specific area.
	 *     @type string $outcome    Filter by outcome.
	 *     @type int    $limit      Max rows (default 50).
	 *     @type int    $offset     Row offset (default 0).
	 * }
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_building( int $building_id, array $args = [] ): array {
		global $wpdb;

		$clauses = array( 'building_id = %d' );
		$params  = array( $building_id );

		if ( isset( $args['area_key'] ) && '' !== $args['area_key'] ) {
			$clauses[] = 'area_key = %s';
			$params[]  = sanitize_key( $args['area_key'] );
		}

		if ( isset( $args['outcome'] ) && in_array( $args['outcome'], self::OUTCOMES, true ) ) {
			$clauses[] = 'outcome = %s';
			$params[]  = $args['outcome'];
		}

		$limit    = isset( $args['limit'] ) ? absint( $args['limit'] ) : 50;
		$offset   = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;
		$params[] = $limit;
		$params[] = $offset;

		$where = implode( ' AND ', $clauses );
		$sql   = "SELECT * FROM {$wpdb->prefix}orgahb_executions
		          WHERE {$where}
		          ORDER BY executed_at DESC
		          LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: array();
	}

	/**
	 * Returns execution rows for a specific process post.
	 *
	 * @param int   $post_id
	 * @param array $args {
	 *     @type string $hotspot_id  Filter to a specific hotspot.
	 *     @type int    $limit       Max rows (default 50).
	 *     @type int    $offset      Row offset (default 0).
	 * }
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_process( int $post_id, array $args = [] ): array {
		global $wpdb;

		$clauses = array( 'post_id = %d' );
		$params  = array( $post_id );

		if ( isset( $args['hotspot_id'] ) && '' !== $args['hotspot_id'] ) {
			$clauses[] = 'hotspot_id = %s';
			$params[]  = sanitize_text_field( $args['hotspot_id'] );
		}

		$limit    = isset( $args['limit'] ) ? absint( $args['limit'] ) : 50;
		$offset   = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;
		$params[] = $limit;
		$params[] = $offset;

		$where = implode( ' AND ', $clauses );
		$sql   = "SELECT * FROM {$wpdb->prefix}orgahb_executions
		          WHERE {$where}
		          ORDER BY executed_at DESC
		          LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: array();
	}

	/**
	 * Checks whether a queue_uuid has already been recorded.
	 * Used to deduplicate offline-synced submissions (spec §19.6).
	 *
	 * @param string $queue_uuid
	 * @return bool
	 */
	public static function uuid_exists( string $queue_uuid ): bool {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}orgahb_executions WHERE queue_uuid = %s",
				$queue_uuid
			)
		);

		return (int) $count > 0;
	}

	// ── Privacy ───────────────────────────────────────────────────────────────

	/**
	 * Nullifies user_id for all execution rows by a deleted user (spec §12.8).
	 *
	 * @param int $user_id
	 * @return void
	 */
	public static function anonymize_user( int $user_id ): void {
		global $wpdb;

		$user  = get_userdata( $user_id );
		$label = $user ? $user->display_name . ' [deleted]' : '[deleted user]';

		$wpdb->update(
			$wpdb->prefix . 'orgahb_executions',
			array( 'user_id' => null, 'historical_user_label' => $label ),
			array( 'user_id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}
}
