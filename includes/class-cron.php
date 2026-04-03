<?php
/**
 * Schedules and manages review-reminder cron events.
 *
 * The cron event `orgahb_daily_review_check` is registered by
 * ORGAHB_Install::activate() (once daily) and unscheduled by
 * ORGAHB_Install::deactivate().
 *
 * On each tick this class:
 *   1. Queries all content CPT posts whose `_orgahb_next_review` date falls
 *      within the configured reminder window (ORGAHB_Settings::review_reminder_days()).
 *   2. Skips posts that have already triggered a reminder for that review date
 *      (tracked in post meta `_orgahb_reminder_sent`).
 *   3. Sends one summary email to the site admin.
 *   4. Stamps the `_orgahb_reminder_sent` meta on each notified post so the
 *      same review date does not trigger a second email.
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Daily review-reminder cron handler.
 */
final class ORGAHB_Cron {

	/** Cron event hook — must match the value in ORGAHB_Install. */
	const HOOK = 'orgahb_daily_review_check';

	/**
	 * Post meta key used to remember that a reminder was already sent for a
	 * particular review date.  Value is the ISO date string (Y-m-d) of the
	 * review date for which the reminder fired.
	 */
	const META_REMINDER_SENT = '_orgahb_reminder_sent';

	/** CPT slugs that carry a review date. */
	const CONTENT_TYPES = array( 'orgahb_page', 'orgahb_process', 'orgahb_document', 'orgahb_building' );

	// ── Hook registration ─────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
	}

	// ── Cron handler ──────────────────────────────────────────────────────────

	/**
	 * Main cron callback — queries upcoming reviews and notifies the admin.
	 *
	 * @return void
	 */
	public static function run(): void {
		$days = ORGAHB_Settings::review_reminder_days();

		$due = self::get_due_posts( $days );
		if ( empty( $due ) ) {
			return;
		}

		// Filter out posts that already received a reminder for this review date.
		$to_notify = array_filter( $due, array( self::class, 'needs_reminder' ) );
		if ( empty( $to_notify ) ) {
			return;
		}

		self::send_notification( $to_notify, $days );

		foreach ( $to_notify as $post ) {
			$review_date = (string) get_post_meta( $post->ID, ORGAHB_Buildings::META_NEXT_REVIEW, true );
			update_post_meta( $post->ID, self::META_REMINDER_SENT, $review_date );
		}
	}

	// ── Query ─────────────────────────────────────────────────────────────────

	/**
	 * Returns all published content posts with a review date between today and
	 * today + $days (inclusive).
	 *
	 * @param int $days  Look-ahead window in days.
	 * @return WP_Post[]
	 */
	private static function get_due_posts( int $days ): array {
		$today    = new DateTimeImmutable( 'today', new DateTimeZone( 'UTC' ) );
		$deadline = $today->modify( "+{$days} days" );

		$query = new WP_Query( array(
			'post_type'      => self::CONTENT_TYPES,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'fields'         => 'all',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => ORGAHB_Buildings::META_NEXT_REVIEW,
					'value'   => array(
						$today->format( 'Y-m-d' ),
						$deadline->format( 'Y-m-d' ),
					),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				),
			),
		) );

		return $query->posts ?: array();
	}

	/**
	 * Returns true if no reminder has been sent for this post's current review date.
	 *
	 * @param WP_Post $post
	 * @return bool
	 */
	private static function needs_reminder( WP_Post $post ): bool {
		$review_date = (string) get_post_meta( $post->ID, ORGAHB_Buildings::META_NEXT_REVIEW, true );
		$sent_for    = (string) get_post_meta( $post->ID, self::META_REMINDER_SENT, true );

		// A reminder was already sent for this exact review date.
		return $sent_for !== $review_date;
	}

	// ── Notification ─────────────────────────────────────────────────────────

	/**
	 * Sends a plain-text review reminder email to the site admin.
	 *
	 * @param WP_Post[] $posts  Posts that need review.
	 * @param int       $days   Reminder window used in the subject line.
	 * @return void
	 */
	private static function send_notification( array $posts, int $days ): void {
		$admin_email = get_option( 'admin_email' );
		if ( ! $admin_email ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );

		$subject = sprintf(
			/* translators: 1: site name, 2: count of items, 3: number of days */
			__( '[%1$s] %2$d content item(s) due for review within %3$d day(s)', 'orgahb-manager' ),
			$site_name,
			count( $posts ),
			$days
		);

		$lines = array(
			sprintf(
				/* translators: 1: count, 2: days */
				__( 'The following %1$d item(s) are scheduled for review within the next %2$d day(s):', 'orgahb-manager' ),
				count( $posts ),
				$days
			),
			'',
		);

		foreach ( $posts as $post ) {
			$review_date = (string) get_post_meta( $post->ID, ORGAHB_Buildings::META_NEXT_REVIEW, true );
			$edit_url    = get_edit_post_link( $post->ID, 'raw' );

			$lines[] = sprintf(
				'  [%s] %s — %s — %s',
				strtoupper( str_replace( 'orgahb_', '', $post->post_type ) ),
				$post->post_title,
				$review_date,
				$edit_url
			);
		}

		$lines[] = '';
		$lines[] = sprintf(
			/* translators: URL */
			__( 'Manage content: %s', 'orgahb-manager' ),
			admin_url( 'admin.php?page=orgahb-handbook' )
		);

		wp_mail( $admin_email, $subject, implode( "\n", $lines ) );
	}
}
