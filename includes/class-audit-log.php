<?php
/**
 * Writes and queries the immutable audit event log.
 *
 * Every workflow action, acknowledgment, and privacy event writes a row here.
 * Rows are never updated or deleted — the table is append-only.
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Audit event writer and query helper.
 *
 * All timestamps are stored in UTC (DATETIME(6)).
 */
final class ORGAHB_Audit_Log {

	// ── Event type constants ──────────────────────────────────────────────────
	// Workflow transitions.
	const EVT_SUBMITTED        = 'submitted_for_review';
	const EVT_APPROVED         = 'approved';
	const EVT_RETURNED         = 'returned_for_revision';
	const EVT_ARCHIVED         = 'archived';
	const EVT_RESTORED         = 'restored';

	// Content interactions.
	const EVT_ACKNOWLEDGED     = 'acknowledged';

	// Administrative.
	const EVT_BUILDING_CREATED = 'building_created';
	const EVT_USER_ANONYMIZED  = 'user_anonymized';
	const EVT_BACKUP_GENERATED = 'backup_generated';
	const EVT_SETTINGS_CHANGED = 'settings_changed';

	// ── Write ─────────────────────────────────────────────────────────────────

	/**
	 * Writes one immutable audit event row.
	 *
	 * @param string   $event_type      One of the EVT_* constants or an ad-hoc type.
	 * @param int|null $post_id         Associated post, or null for non-post events.
	 * @param int|null $actor_user_id   The acting WordPress user, or null.
	 * @param array    $args {
	 *     Optional contextual args.
	 *
	 *     @type string $from_status      Previous post status.
	 *     @type string $to_status        New post status.
	 *     @type int    $post_revision_id Associated WP revision post ID.
	 *     @type string $comment_text     Reviewer comment or free-text note.
	 *     @type array  $metadata         Arbitrary key/value pairs stored as JSON.
	 *     @type string $occurred_at      UTC datetime override (Y-m-d H:i:s.u).
	 *                                    Defaults to current_time('mysql', true).
	 * }
	 * @return int  Inserted row ID, or 0 on failure.
	 */
	public static function write(
		string $event_type,
		?int   $post_id,
		?int   $actor_user_id,
		array  $args = []
	): int {
		global $wpdb;

		$user  = $actor_user_id ? get_userdata( $actor_user_id ) : null;
		$label = $user
			? $user->display_name
			: ( isset( $args['historical_actor_label'] ) ? (string) $args['historical_actor_label'] : '' );

		$metadata_json = isset( $args['metadata'] ) && is_array( $args['metadata'] )
			? wp_json_encode( $args['metadata'] )
			: null;

		$occurred_at = $args['occurred_at'] ?? self::utc_now();

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'orgahb_audit_events',
			array(
				'post_id'                => $post_id,
				'actor_user_id'          => $actor_user_id,
				'historical_actor_label' => $label,
				'event_type'             => $event_type,
				'from_status'            => $args['from_status'] ?? null,
				'to_status'              => $args['to_status'] ?? null,
				'post_revision_id'       => $args['post_revision_id'] ?? null,
				'comment_text'           => $args['comment_text'] ?? null,
				'metadata_json'          => $metadata_json,
				'occurred_at'            => $occurred_at,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Nullifies the actor_user_id for all events by a deleted user while
	 * preserving the historical display label.
	 *
	 * Called from ORGAHB_Privacy when a user is deleted (spec §12.8).
	 *
	 * @param int $user_id
	 * @return void
	 */
	public static function anonymize_user( int $user_id ): void {
		global $wpdb;

		$user  = get_userdata( $user_id );
		$label = $user
			? $user->display_name . ' [deleted]'
			: '[deleted user]';

		$wpdb->update(
			$wpdb->prefix . 'orgahb_audit_events',
			array( 'actor_user_id' => null, 'historical_actor_label' => $label ),
			array( 'actor_user_id' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	// ── Query ─────────────────────────────────────────────────────────────────

	/**
	 * Queries audit events with optional filters.
	 *
	 * @param array $args {
	 *     @type int    $post_id     Filter by post.
	 *     @type int    $user_id     Filter by actor user.
	 *     @type string $event_type  Filter by event type.
	 *     @type string $date_from   UTC date string (Y-m-d).
	 *     @type string $date_to     UTC date string (Y-m-d).
	 *     @type int    $limit       Max rows (default 100).
	 *     @type int    $offset      Row offset (default 0).
	 * }
	 * @return array<int, array<string, mixed>>
	 */
	public static function query( array $args = [] ): array {
		global $wpdb;

		$table   = $wpdb->prefix . 'orgahb_audit_events';
		$clauses = array( '1=1' );
		$params  = array();

		if ( isset( $args['post_id'] ) ) {
			$clauses[] = 'post_id = %d';
			$params[]  = (int) $args['post_id'];
		}

		if ( isset( $args['user_id'] ) ) {
			$clauses[] = 'actor_user_id = %d';
			$params[]  = (int) $args['user_id'];
		}

		if ( isset( $args['event_type'] ) && '' !== $args['event_type'] ) {
			$clauses[] = 'event_type = %s';
			$params[]  = $args['event_type'];
		}

		if ( isset( $args['date_from'] ) && '' !== $args['date_from'] ) {
			$clauses[] = 'occurred_at >= %s';
			$params[]  = $args['date_from'] . ' 00:00:00';
		}

		if ( isset( $args['date_to'] ) && '' !== $args['date_to'] ) {
			$clauses[] = 'occurred_at <= %s';
			$params[]  = $args['date_to'] . ' 23:59:59';
		}

		$limit  = isset( $args['limit'] ) ? absint( $args['limit'] ) : 100;
		$offset = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

		$where = implode( ' AND ', $clauses );
		$sql   = "SELECT * FROM {$table} WHERE {$where} ORDER BY occurred_at DESC LIMIT %d OFFSET %d";

		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: array();
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Returns the current UTC datetime formatted for DATETIME(6) columns.
	 *
	 * @return string  e.g. "2026-04-01 14:32:00.123456"
	 */
	private static function utc_now(): string {
		$dt = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		return $dt->format( 'Y-m-d H:i:s.u' );
	}
}
