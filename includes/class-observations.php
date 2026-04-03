<?php
/**
 * Building observation recording and querying.
 *
 * Observations are lightweight, staff-created notes about a building.
 * They are NOT a ticket/complaint system — they are contextual notes that
 * live inside the handbook view (spec §7.5, §2.3, §4.5).
 *
 * Status flow: open → in_progress → resolved / closed
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Building observation writer, updater, and query helper.
 */
final class ORGAHB_Observations {

	/** Valid status values. */
	const STATUSES = array( 'open', 'in_progress', 'resolved', 'closed' );

	/** Statuses that mark the observation as "done" for query filtering. */
	const RESOLVED_STATUSES = array( 'resolved', 'closed' );

	// ── Write ─────────────────────────────────────────────────────────────────

	/**
	 * Creates a new building observation.
	 *
	 * @param array $data {
	 *     Required:
	 *     @type int    $building_id  orgahb_building post ID.
	 *     @type string $category     Short category label.
	 *     @type string $summary      One-line summary (max 255 chars).
	 *
	 *     Optional:
	 *     @type int    $author_user_id         Defaults to current user.
	 *     @type string $area_key               Building area slug.
	 *     @type string $details                Longer description (HTML allowed via wp_kses_post).
	 *     @type string $external_reference     External CRM / ticket reference string.
	 *     @type string $queue_uuid             Client UUID for offline dedup (spec §33.8).
	 *     @type string $source                 'manual' (default) or 'api'.
	 *     @type string $created_at             UTC datetime override (Y-m-d H:i:s[.u]).
	 * }
	 * @return int  Inserted row ID, or 0 on failure.
	 */
	public static function create( array $data ): int {
		global $wpdb;

		$user_id = isset( $data['author_user_id'] )
			? (int) $data['author_user_id']
			: ( get_current_user_id() ?: null );

		$user  = $user_id ? get_userdata( $user_id ) : null;
		$label = $user ? $user->display_name : ( $data['historical_author_label'] ?? '' );

		$dt         = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$created_at = $data['created_at'] ?? $dt->format( 'Y-m-d H:i:s.u' );

		$row = array(
			'building_id'             => (int) $data['building_id'],
			'area_key'                => isset( $data['area_key'] ) ? sanitize_key( $data['area_key'] ) : null,
			'author_user_id'          => $user_id,
			'historical_author_label' => $label,
			'category'                => sanitize_text_field( $data['category'] ?? '' ),
			'status'                  => 'open',
			'summary'                 => sanitize_text_field( substr( (string) ( $data['summary'] ?? '' ), 0, 255 ) ),
			'details'                 => isset( $data['details'] ) ? wp_kses_post( $data['details'] ) : null,
			'external_reference'      => isset( $data['external_reference'] )
				? sanitize_text_field( $data['external_reference'] )
				: null,
			'source'                  => $data['source'] ?? 'manual',
			'created_at'              => $created_at,
		);
		$fmt = array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		if ( ! empty( $data['queue_uuid'] ) ) {
			$row['queue_uuid'] = sanitize_text_field( $data['queue_uuid'] );
			$fmt[]             = '%s';
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'orgahb_observations',
			$row,
			$fmt
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Checks whether a queue_uuid has already been recorded (offline dedup).
	 *
	 * @param string $queue_uuid
	 * @return bool
	 */
	public static function uuid_exists( string $queue_uuid ): bool {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}orgahb_observations WHERE queue_uuid = %s",
				$queue_uuid
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Updates the status of an observation.
	 *
	 * When status moves to 'resolved' or 'closed', the resolver and timestamp
	 * are recorded on the row.
	 *
	 * @param int    $obs_id
	 * @param string $status   One of self::STATUSES.
	 * @param int    $user_id  The user performing the status change.
	 * @return bool
	 */
	public static function update_status( int $obs_id, string $status, int $user_id ): bool {
		global $wpdb;

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}

		$update = array( 'status' => $status );
		$format = array( '%s' );

		if ( in_array( $status, self::RESOLVED_STATUSES, true ) ) {
			$user              = get_userdata( $user_id );
			$dt                = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

			$update['resolved_by_user_id']       = $user_id;
			$update['historical_resolver_label'] = $user ? $user->display_name : '';
			$update['resolved_at']               = $dt->format( 'Y-m-d H:i:s.u' );

			$format[] = '%d';
			$format[] = '%s';
			$format[] = '%s';
		}

		return false !== $wpdb->update(
			$wpdb->prefix . 'orgahb_observations',
			$update,
			array( 'id' => $obs_id ),
			$format,
			array( '%d' )
		);
	}

	// ── Query ─────────────────────────────────────────────────────────────────

	/**
	 * Returns observations for a building with optional filters.
	 *
	 * @param int   $building_id
	 * @param array $args {
	 *     @type string       $status    Filter by status (or 'open_only' shorthand).
	 *     @type string       $area_key  Filter by area.
	 *     @type int          $limit     Max rows (default 50).
	 *     @type int          $offset    Row offset (default 0).
	 * }
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_building( int $building_id, array $args = [] ): array {
		global $wpdb;

		$clauses = array( 'building_id = %d' );
		$params  = array( $building_id );

		// Convenience shorthand: 'open_only' maps to non-resolved statuses.
		if ( isset( $args['status'] ) ) {
			if ( 'open_only' === $args['status'] ) {
				$placeholders = implode( ', ', array_fill( 0, count( self::RESOLVED_STATUSES ), '%s' ) );
				$clauses[]    = "status NOT IN ({$placeholders})";
				foreach ( self::RESOLVED_STATUSES as $s ) {
					$params[] = $s;
				}
			} elseif ( in_array( $args['status'], self::STATUSES, true ) ) {
				$clauses[] = 'status = %s';
				$params[]  = $args['status'];
			}
		}

		if ( isset( $args['area_key'] ) && '' !== $args['area_key'] ) {
			$clauses[] = 'area_key = %s';
			$params[]  = sanitize_key( $args['area_key'] );
		}

		$limit    = isset( $args['limit'] ) ? absint( $args['limit'] ) : 50;
		$offset   = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;
		$params[] = $limit;
		$params[] = $offset;

		$where = implode( ' AND ', $clauses );
		$sql   = "SELECT * FROM {$wpdb->prefix}orgahb_observations
		          WHERE {$where}
		          ORDER BY created_at DESC
		          LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: array();
	}

	// ── Privacy ───────────────────────────────────────────────────────────────

	/**
	 * Nullifies user references for a deleted user (spec §12.8).
	 * Preserves historical labels on all rows the user authored or resolved.
	 *
	 * @param int $user_id
	 * @return void
	 */
	public static function anonymize_user( int $user_id ): void {
		global $wpdb;

		$user  = get_userdata( $user_id );
		$label = $user ? $user->display_name . ' [deleted]' : '[deleted user]';

		// Authored rows.
		$wpdb->update(
			$wpdb->prefix . 'orgahb_observations',
			array( 'author_user_id' => null, 'historical_author_label' => $label ),
			array( 'author_user_id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		// Resolved rows.
		$wpdb->update(
			$wpdb->prefix . 'orgahb_observations',
			array( 'resolved_by_user_id' => null, 'historical_resolver_label' => $label ),
			array( 'resolved_by_user_id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}
}
