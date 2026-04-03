<?php
/**
 * Content acknowledgment recording and querying.
 *
 * Acknowledgments are immutable — one row is written per user per approved
 * revision, never updated.  The audit log also receives an EVT_ACKNOWLEDGED
 * event for every record.
 *
 * Applies to: orgahb_page and orgahb_document (spec §22.1).
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Acknowledgment writer and query helper.
 */
final class ORGAHB_Acknowledgments {

	/** Allowed content types for acknowledgment. */
	const APPLICABLE_TYPES = array( 'orgahb_page', 'orgahb_document' );

	// ── Write ─────────────────────────────────────────────────────────────────

	/**
	 * Records that a user has acknowledged the current approved revision.
	 *
	 * The caller should pass the WP revision post ID (not a sequence number)
	 * so the record can be tied back to the exact content state (spec §19.1).
	 *
	 * A user may acknowledge the same revision more than once (e.g. re-reading)
	 * — there is no unique constraint on (user_id, post_id, post_revision_id).
	 *
	 * @param int    $post_id       The orgahb_page or orgahb_document post ID.
	 * @param int    $user_id       The acknowledging user.
	 * @param int    $revision_id   The WP revision post ID current at time of ack.
	 * @param string $version_label Optional human-readable version label.
	 * @param string $source        'ui' or 'api'. Defaults to 'ui'.
	 * @param string $queue_uuid    Optional client UUID for offline dedup (spec §33.8).
	 * @return int  Inserted row ID, or 0 on failure.
	 */
	public static function record(
		int    $post_id,
		int    $user_id,
		int    $revision_id,
		string $version_label = '',
		string $source        = 'ui',
		string $queue_uuid    = ''
	): int {
		global $wpdb;

		$user  = get_userdata( $user_id );
		$label = $user ? $user->display_name : '';

		$dt = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		$row = array(
			'post_id'               => $post_id,
			'user_id'               => $user_id,
			'historical_user_label' => $label,
			'acknowledged_at'       => $dt->format( 'Y-m-d H:i:s.u' ),
			'post_revision_id'      => $revision_id,
			'post_version_label'    => $version_label,
			'source'                => $source,
		);
		$fmt = array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' );

		if ( '' !== $queue_uuid ) {
			$row['queue_uuid'] = $queue_uuid;
			$fmt[]             = '%s';
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'orgahb_acknowledgments',
			$row,
			$fmt
		);

		if ( ! $inserted ) {
			return 0;
		}

		$row_id = (int) $wpdb->insert_id;

		ORGAHB_Audit_Log::write(
			ORGAHB_Audit_Log::EVT_ACKNOWLEDGED,
			$post_id,
			$user_id,
			array(
				'post_revision_id' => $revision_id,
				'metadata'         => array(
					'version_label' => $version_label,
					'source'        => $source,
				),
			)
		);

		return $row_id;
	}

	// ── Query ─────────────────────────────────────────────────────────────────

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
				"SELECT COUNT(*) FROM {$wpdb->prefix}orgahb_acknowledgments WHERE queue_uuid = %s",
				$queue_uuid
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Checks whether a user has acknowledged a specific revision of a post.
	 *
	 * @param int $post_id
	 * @param int $user_id
	 * @param int $revision_id
	 * @return bool
	 */
	public static function has_acknowledged( int $post_id, int $user_id, int $revision_id ): bool {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}orgahb_acknowledgments
				 WHERE post_id = %d
				   AND user_id = %d
				   AND post_revision_id = %d",
				$post_id,
				$user_id,
				$revision_id
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Returns acknowledgment rows for a post.
	 *
	 * @param int   $post_id
	 * @param array $args {
	 *     @type int    $revision_id  Filter to a specific revision.
	 *     @type int    $limit        Max rows (default 100).
	 *     @type int    $offset       Row offset (default 0).
	 * }
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_post( int $post_id, array $args = [] ): array {
		global $wpdb;

		$where  = 'post_id = %d';
		$params = array( $post_id );

		if ( isset( $args['revision_id'] ) ) {
			$where   .= ' AND post_revision_id = %d';
			$params[] = (int) $args['revision_id'];
		}

		$limit    = isset( $args['limit'] ) ? absint( $args['limit'] ) : 100;
		$offset   = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;
		$params[] = $limit;
		$params[] = $offset;

		$sql = "SELECT * FROM {$wpdb->prefix}orgahb_acknowledgments
		        WHERE {$where}
		        ORDER BY acknowledged_at DESC
		        LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: array();
	}

	/**
	 * Returns acknowledgment rows for a user.
	 *
	 * @param int   $user_id
	 * @param array $args {
	 *     @type int $limit   Max rows (default 100).
	 *     @type int $offset  Row offset (default 0).
	 * }
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_user( int $user_id, array $args = [] ): array {
		global $wpdb;

		$limit  = isset( $args['limit'] ) ? absint( $args['limit'] ) : 100;
		$offset = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}orgahb_acknowledgments
				 WHERE user_id = %d
				 ORDER BY acknowledged_at DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		) ?: array();
	}

	// ── Privacy ───────────────────────────────────────────────────────────────

	/**
	 * Nullifies user_id for all acknowledgment rows by a deleted user,
	 * preserving the historical display label (spec §12.8).
	 *
	 * @param int $user_id
	 * @return void
	 */
	public static function anonymize_user( int $user_id ): void {
		global $wpdb;

		$user  = get_userdata( $user_id );
		$label = $user ? $user->display_name . ' [deleted]' : '[deleted user]';

		$wpdb->update(
			$wpdb->prefix . 'orgahb_acknowledgments',
			array( 'user_id' => null, 'historical_user_label' => $label ),
			array( 'user_id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}
}
