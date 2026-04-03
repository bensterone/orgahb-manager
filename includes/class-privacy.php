<?php
/**
 * Registers WordPress privacy hooks, exporters, and erasers.
 *
 * Hooks:
 *   - deleted_user   — anonymizes rows in all 4 custom tables when a WP user
 *                      account is permanently deleted (spec §12.8).
 *   - wp_privacy_personal_data_exporters — surfaces user data for the
 *                      WP "Export Personal Data" tool.
 *   - wp_privacy_personal_data_erasers   — erasure via the WP privacy tools
 *                      calls anonymize_user() on all tables (supplement to
 *                      deleted_user which fires only on hard deletion).
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Privacy hook wiring: user deletion, data export, data erasure.
 */
final class ORGAHB_Privacy {

	// ── Hook registration ─────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_init',                            array( self::class, 'register_privacy_policy_content' ) );
		add_action( 'deleted_user',                          array( self::class, 'on_user_deleted' ), 10, 1 );
		add_filter( 'wp_privacy_personal_data_exporters',   array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers',     array( self::class, 'register_eraser'   ) );
	}

	// ── Privacy policy content ───────────────────────────────────────────────

	/**
	 * Registers suggested privacy policy text for the WP Privacy Policy Guide.
	 *
	 * @return void
	 */
	public static function register_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<h2>' . __( 'Handbook Manager', 'orgahb-manager' ) . '</h2>';
		$content .= '<p>' . __( 'This site uses the OrgaHB Manager plugin to run a building-specific organizational handbook. The following personal data may be collected and stored:', 'orgahb-manager' ) . '</p>';
		$content .= '<ul>';
		$content .= '<li>' . __( '<strong>Acknowledgments</strong>: your user ID and display name are recorded when you acknowledge a handbook page or controlled document, along with the revision and timestamp.', 'orgahb-manager' ) . '</li>';
		$content .= '<li>' . __( '<strong>Process step evidence</strong>: your user ID and display name are recorded when you log an outcome against a process diagram hotspot, along with the building, step, outcome, optional note, and timestamp.', 'orgahb-manager' ) . '</li>';
		$content .= '<li>' . __( '<strong>Observations</strong>: your user ID and display name are recorded as the author of any building observation you create.', 'orgahb-manager' ) . '</li>';
		$content .= '<li>' . __( '<strong>Audit log</strong>: content workflow events (submit for review, approve, archive, etc.) record the acting user\'s ID and display name.', 'orgahb-manager' ) . '</li>';
		$content .= '</ul>';
		$content .= '<p>' . __( 'This data is used solely for internal operational records, traceability, and governance. It is not shared with third parties. If your account is deleted, your user ID is nullified in all records and replaced with your display name marked as deleted, preserving the audit trail without retaining an active identity reference.', 'orgahb-manager' ) . '</p>';

		wp_add_privacy_policy_content(
			__( 'Handbook Manager', 'orgahb-manager' ),
			wp_kses_post( wpautop( $content, false ) )
		);
	}

	// ── User deletion ─────────────────────────────────────────────────────────

	/**
	 * Anonymizes all plugin rows that reference the deleted user.
	 *
	 * Called on the `deleted_user` action, which fires AFTER the WordPress
	 * user row has been removed from wp_users.  Each data class stores a
	 * `historical_*_label` column so the display name can be snapshotted
	 * before the row is nullified.
	 *
	 * Note: get_userdata() will return false at this point for a hard-deleted
	 * user.  Each anonymize_user() implementation handles the null case
	 * by falling back to "[deleted user]".
	 *
	 * @param int $user_id  The ID of the deleted WP user.
	 * @return void
	 */
	public static function on_user_deleted( int $user_id ): void {
		ORGAHB_Acknowledgments::anonymize_user( $user_id );
		ORGAHB_Executions::anonymize_user( $user_id );
		ORGAHB_Observations::anonymize_user( $user_id );
		ORGAHB_Audit_Log::anonymize_user( $user_id );
	}

	// ── Personal data export ──────────────────────────────────────────────────

	/**
	 * Registers the plugin's personal data exporter with WordPress.
	 *
	 * @param array $exporters
	 * @return array
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['orgahb-manager'] = array(
			'exporter_friendly_name' => __( 'Handbook Manager', 'orgahb-manager' ),
			'callback'               => array( self::class, 'export_user_data' ),
		);
		return $exporters;
	}

	/**
	 * Collects all plugin data for a given email address.
	 *
	 * WordPress paginates exporter calls (page parameter, 1-based).
	 * We return everything in one page since row counts are small.
	 *
	 * @param string $email_address
	 * @param int    $page          1-based page number (unused — single page).
	 * @return array{ data: array, done: bool }
	 */
	public static function export_user_data( string $email_address, int $page = 1 ): array {
		global $wpdb;

		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}

		$data = array();

		// Acknowledgments.
		$acks = ORGAHB_Acknowledgments::get_for_user( $user->ID, array( 'limit' => 500 ) );
		foreach ( $acks as $row ) {
			$data[] = array(
				'group_id'    => 'orgahb_acknowledgments',
				'group_label' => __( 'Handbook Acknowledgments', 'orgahb-manager' ),
				'item_id'     => 'orgahb_ack_' . $row['id'],
				'data'        => array(
					array( 'name' => __( 'Content ID',      'orgahb-manager' ), 'value' => $row['post_id'] ),
					array( 'name' => __( 'Version',         'orgahb-manager' ), 'value' => $row['post_version_label'] ),
					array( 'name' => __( 'Acknowledged at', 'orgahb-manager' ), 'value' => $row['acknowledged_at'] ),
					array( 'name' => __( 'Source',          'orgahb-manager' ), 'value' => $row['source'] ),
				),
			);
		}

		// Executions — no get_for_user() helper exists; query directly.
		$execs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}orgahb_executions WHERE user_id = %d LIMIT 500",
				$user->ID
			),
			ARRAY_A
		) ?: array();
		foreach ( $execs as $row ) {
			$data[] = array(
				'group_id'    => 'orgahb_executions',
				'group_label' => __( 'Handbook Process Executions', 'orgahb-manager' ),
				'item_id'     => 'orgahb_exec_' . $row['id'],
				'data'        => array(
					array( 'name' => __( 'Process ID',   'orgahb-manager' ), 'value' => $row['post_id'] ),
					array( 'name' => __( 'Building ID',  'orgahb-manager' ), 'value' => $row['building_id'] ),
					array( 'name' => __( 'Hotspot',      'orgahb-manager' ), 'value' => $row['hotspot_id'] ),
					array( 'name' => __( 'Outcome',      'orgahb-manager' ), 'value' => $row['outcome'] ),
					array( 'name' => __( 'Note',         'orgahb-manager' ), 'value' => $row['note'] ),
					array( 'name' => __( 'Executed at',  'orgahb-manager' ), 'value' => $row['executed_at'] ),
				),
			);
		}

		// Observations — no get_for_user() helper exists; query directly.
		$obs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}orgahb_observations WHERE author_user_id = %d LIMIT 500",
				$user->ID
			),
			ARRAY_A
		) ?: array();
		foreach ( $obs as $row ) {
			$data[] = array(
				'group_id'    => 'orgahb_observations',
				'group_label' => __( 'Handbook Observations', 'orgahb-manager' ),
				'item_id'     => 'orgahb_obs_' . $row['id'],
				'data'        => array(
					array( 'name' => __( 'Building ID', 'orgahb-manager' ), 'value' => $row['building_id'] ),
					array( 'name' => __( 'Category',    'orgahb-manager' ), 'value' => $row['category'] ),
					array( 'name' => __( 'Summary',     'orgahb-manager' ), 'value' => $row['summary'] ),
					array( 'name' => __( 'Status',      'orgahb-manager' ), 'value' => $row['status'] ),
					array( 'name' => __( 'Created at',  'orgahb-manager' ), 'value' => $row['created_at'] ),
				),
			);
		}

		return array( 'data' => $data, 'done' => true );
	}

	// ── Personal data erasure ─────────────────────────────────────────────────

	/**
	 * Registers the plugin's personal data eraser with WordPress.
	 *
	 * @param array $erasers
	 * @return array
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['orgahb-manager'] = array(
			'eraser_friendly_name' => __( 'Handbook Manager', 'orgahb-manager' ),
			'callback'             => array( self::class, 'erase_user_data' ),
		);
		return $erasers;
	}

	/**
	 * Anonymizes all plugin rows for a given email address.
	 *
	 * The WP erasure tool calls this before (or instead of) hard-deleting the
	 * user, so we must look up the user here rather than relying on the
	 * deleted_user hook.
	 *
	 * @param string $email_address
	 * @param int    $page          Unused — single page.
	 * @return array{ items_removed: bool, items_retained: bool, messages: string[], done: bool }
	 */
	public static function erase_user_data( string $email_address, int $page = 1 ): array {
		$result = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return $result;
		}

		ORGAHB_Acknowledgments::anonymize_user( $user->ID );
		ORGAHB_Executions::anonymize_user( $user->ID );
		ORGAHB_Observations::anonymize_user( $user->ID );
		ORGAHB_Audit_Log::anonymize_user( $user->ID );

		$result['items_removed'] = true;
		return $result;
	}
}
