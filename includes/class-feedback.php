<?php
/**
 * Feedback notifications for new building observations (spec §3.1 Ring A item 20).
 *
 * When a field operator submits a building observation through the handbook
 * viewer, this class sends a notification email to the building's owner so
 * that the right person is aware without having to poll the admin Reports page.
 *
 * The notification is intentionally lightweight:
 *   - one email per observation, sent immediately,
 *   - recipient: the building's owner user (META_OWNER_USER_ID),
 *     falling back to the site admin email if no owner is set,
 *   - subject + plain-text body only (no HTML templates).
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Observation notification dispatcher.
 */
final class ORGAHB_Feedback {

	// ── Hook registration ─────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( 'orgahb_observation_created', array( self::class, 'on_observation_created' ), 10, 3 );
	}

	// ── Handler ───────────────────────────────────────────────────────────────

	/**
	 * Sends a notification email when a new observation is created.
	 *
	 * @param int   $row_id       Inserted observation ID (unused but provided for completeness).
	 * @param int   $building_id  Building post ID.
	 * @param array $data         Data passed to ORGAHB_Observations::create().
	 * @return void
	 */
	public static function on_observation_created( int $row_id, int $building_id, array $data ): void {
		$building = get_post( $building_id );
		if ( ! $building ) {
			return;
		}

		$recipient = self::resolve_recipient( $building_id );
		if ( ! $recipient ) {
			return;
		}

		self::send( $recipient, $building, $data );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Resolves the notification recipient email address.
	 *
	 * Priority:
	 *   1. Building owner (META_OWNER_USER_ID)
	 *   2. Building deputy (META_DEPUTY_USER_ID)
	 *   3. Site admin email
	 *
	 * @param int $building_id
	 * @return string  Valid email address, or empty string if none found.
	 */
	private static function resolve_recipient( int $building_id ): string {
		$owner_id  = (int) get_post_meta( $building_id, ORGAHB_Buildings::META_OWNER_USER_ID, true );
		$deputy_id = (int) get_post_meta( $building_id, ORGAHB_Buildings::META_DEPUTY_USER_ID, true );

		foreach ( array( $owner_id, $deputy_id ) as $uid ) {
			if ( $uid > 0 ) {
				$user = get_userdata( $uid );
				if ( $user && $user->user_email ) {
					return $user->user_email;
				}
			}
		}

		return (string) get_option( 'admin_email', '' );
	}

	/**
	 * Composes and sends the notification email.
	 *
	 * @param string  $to        Recipient email address.
	 * @param WP_Post $building  Building post object.
	 * @param array   $data      Observation data array.
	 * @return void
	 */
	private static function send( string $to, WP_Post $building, array $data ): void {
		$site_name = get_bloginfo( 'name' );
		$category  = $data['category'] ?? 'general';
		$summary   = $data['summary']  ?? '';
		$area_key  = $data['area_key'] ?? '';

		$subject = sprintf(
			/* translators: 1: site name 2: building title */
			__( '[%1$s] New observation for %2$s', 'orgahb-manager' ),
			$site_name,
			$building->post_title
		);

		$author_id = $data['author_user_id'] ?? 0;
		$author    = $author_id ? get_userdata( $author_id ) : null;
		$author_name = $author ? $author->display_name : __( 'Unknown', 'orgahb-manager' );

		$lines = array(
			sprintf(
				/* translators: building title */
				__( 'A new observation has been submitted for building "%s".', 'orgahb-manager' ),
				$building->post_title
			),
			'',
			/* translators: %s: observation category */
			sprintf( __( 'Category : %s', 'orgahb-manager' ), $category ),
			/* translators: %s: observation summary text */
			sprintf( __( 'Summary  : %s', 'orgahb-manager' ), $summary ),
		);

		if ( $area_key ) {
			/* translators: %s: area key/name */
			$lines[] = sprintf( __( 'Area     : %s', 'orgahb-manager' ), $area_key );
		}

		if ( ! empty( $data['external_reference'] ) ) {
			/* translators: %s: external reference string */
			$lines[] = sprintf( __( 'Ext. ref.: %s', 'orgahb-manager' ), $data['external_reference'] );
		}

		/* translators: %s: submitter's display name */
		$lines[] = sprintf( __( 'Submitted by: %s', 'orgahb-manager' ), $author_name );
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: URL */
			__( 'View reports: %s', 'orgahb-manager' ),
			admin_url( 'admin.php?page=orgahb-reports&type=observations&building_id=' . $building->ID )
		);

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}
}
