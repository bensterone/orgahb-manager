<?php
/**
 * Approval workflow state machine.
 *
 * Manages content lifecycle transitions (spec §20):
 *   draft → pending  (submit for review)
 *   pending → publish (approve)
 *   pending → draft   (return for revision)
 *   any → orgahb_archived (archive)
 *   orgahb_archived → draft (restore)
 *
 * Every transition writes an audit event row.
 * Return-for-revision requires a reviewer comment when the plugin setting is on.
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Content workflow state machine.
 */
final class ORGAHB_Workflow {

	/** Custom post status for archived content. */
	const STATUS_ARCHIVED = 'orgahb_archived';

	/** CPTs to which workflow rules apply (spec §20.2). */
	const APPLICABLE_TYPES = array(
		'orgahb_page',
		'orgahb_process',
		'orgahb_document',
		'orgahb_building',
	);

	// ── Hook registration ─────────────────────────────────────────────────────

	/**
	 * Hook registration — called from ORGAHB_Plugin::init_components().
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_status' ) );
		add_filter( 'display_post_states', array( self::class, 'display_post_states' ), 10, 2 );
	}

	/**
	 * Registers the orgahb_archived post status with WordPress.
	 *
	 * @return void
	 */
	public static function register_status(): void {
		register_post_status(
			self::STATUS_ARCHIVED,
			array(
				'label'                     => _x( 'Archived', 'post status', 'orgahb-manager' ),
				'public'                    => false,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of archived items */
				'label_count'               => _n_noop( 'Archived (%s)', 'Archived (%s)', 'orgahb-manager' ),
			)
		);
	}

	/**
	 * Adds the 'Archived' label in WP admin post list status column.
	 *
	 * @param string[]  $states
	 * @param \WP_Post  $post
	 * @return string[]
	 */
	public static function display_post_states( array $states, \WP_Post $post ): array {
		if ( self::STATUS_ARCHIVED === $post->post_status ) {
			$states[ self::STATUS_ARCHIVED ] = __( 'Archived', 'orgahb-manager' );
		}
		return $states;
	}

	// ── Transition methods ────────────────────────────────────────────────────

	/**
	 * Submits content for review: draft → pending.
	 *
	 * @param int $post_id
	 * @param int $user_id  The editor submitting the item.
	 * @return true|\WP_Error
	 */
	public static function submit_for_review( int $post_id, int $user_id ): true|\WP_Error {
		$check = self::validate_post( $post_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$post = $check;

		if ( ! in_array( $post->post_status, array( 'draft', 'auto-draft' ), true ) ) {
			return new \WP_Error(
				'orgahb_wrong_status',
				__( 'Only draft content can be submitted for review.', 'orgahb-manager' )
			);
		}

		if ( ! user_can( $user_id, 'submit_orgahb_content' ) ) {
			return new \WP_Error( 'orgahb_no_permission', __( 'You do not have permission to submit content for review.', 'orgahb-manager' ) );
		}

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'pending' ) );

		ORGAHB_Audit_Log::write(
			ORGAHB_Audit_Log::EVT_SUBMITTED,
			$post_id,
			$user_id,
			array(
				'from_status'      => $post->post_status,
				'to_status'        => 'pending',
				'post_revision_id' => self::current_revision_id( $post_id ),
			)
		);

		return true;
	}

	/**
	 * Approves content: pending → publish.
	 *
	 * @param int         $post_id
	 * @param int         $user_id  The reviewer approving.
	 * @param string|null $comment  Optional approval note.
	 * @return true|\WP_Error
	 */
	public static function approve( int $post_id, int $user_id, ?string $comment = null ): true|\WP_Error {
		$check = self::validate_post( $post_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$post = $check;

		if ( 'pending' !== $post->post_status ) {
			return new \WP_Error(
				'orgahb_wrong_status',
				__( 'Only content pending review can be approved.', 'orgahb-manager' )
			);
		}

		if ( ! user_can( $user_id, 'approve_orgahb_content' ) ) {
			return new \WP_Error( 'orgahb_no_permission', __( 'You do not have permission to approve content.', 'orgahb-manager' ) );
		}

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );

		ORGAHB_Audit_Log::write(
			ORGAHB_Audit_Log::EVT_APPROVED,
			$post_id,
			$user_id,
			array(
				'from_status'      => 'pending',
				'to_status'        => 'publish',
				'post_revision_id' => self::current_revision_id( $post_id ),
				'comment_text'     => $comment ?? '',
			)
		);

		return true;
	}

	/**
	 * Returns content for revision: pending → draft.
	 *
	 * A non-empty reviewer comment is required when the plugin setting is on.
	 *
	 * @param int    $post_id
	 * @param int    $user_id
	 * @param string $comment  Mandatory reviewer note explaining what needs changing.
	 * @return true|\WP_Error
	 */
	public static function return_for_revision( int $post_id, int $user_id, string $comment ): true|\WP_Error {
		if ( ORGAHB_Settings::require_reviewer_comment() && '' === trim( $comment ) ) {
			return new \WP_Error(
				'orgahb_comment_required',
				__( 'A reviewer comment is required when returning content for revision.', 'orgahb-manager' )
			);
		}

		$check = self::validate_post( $post_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$post = $check;

		if ( 'pending' !== $post->post_status ) {
			return new \WP_Error(
				'orgahb_wrong_status',
				__( 'Only content pending review can be returned for revision.', 'orgahb-manager' )
			);
		}

		if ( ! user_can( $user_id, 'approve_orgahb_content' ) ) {
			return new \WP_Error( 'orgahb_no_permission', __( 'You do not have permission to return content for revision.', 'orgahb-manager' ) );
		}

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );

		ORGAHB_Audit_Log::write(
			ORGAHB_Audit_Log::EVT_RETURNED,
			$post_id,
			$user_id,
			array(
				'from_status'  => 'pending',
				'to_status'    => 'draft',
				'comment_text' => $comment,
			)
		);

		return true;
	}

	/**
	 * Archives content: any status → orgahb_archived.
	 *
	 * @param int    $post_id
	 * @param int    $user_id
	 * @param string $reason  Optional rationale stored in audit log and post meta.
	 * @return true|\WP_Error
	 */
	public static function archive( int $post_id, int $user_id, string $reason = '' ): true|\WP_Error {
		$check = self::validate_post( $post_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$post = $check;

		if ( self::STATUS_ARCHIVED === $post->post_status ) {
			return new \WP_Error( 'orgahb_already_archived', __( 'This content is already archived.', 'orgahb-manager' ) );
		}

		if ( ! user_can( $user_id, 'approve_orgahb_content' ) ) {
			return new \WP_Error( 'orgahb_no_permission', __( 'You do not have permission to archive content.', 'orgahb-manager' ) );
		}

		$from = $post->post_status;
		wp_update_post( array( 'ID' => $post_id, 'post_status' => self::STATUS_ARCHIVED ) );

		// Persist the archive reason in post meta for display.
		if ( '' !== $reason ) {
			update_post_meta( $post_id, '_orgahb_archived_reason', sanitize_text_field( $reason ) );
		}

		ORGAHB_Audit_Log::write(
			ORGAHB_Audit_Log::EVT_ARCHIVED,
			$post_id,
			$user_id,
			array(
				'from_status'  => $from,
				'to_status'    => self::STATUS_ARCHIVED,
				'comment_text' => $reason,
			)
		);

		return true;
	}

	/**
	 * Restores archived content back to draft.
	 *
	 * @param int $post_id
	 * @param int $user_id
	 * @return true|\WP_Error
	 */
	public static function restore( int $post_id, int $user_id ): true|\WP_Error {
		$check = self::validate_post( $post_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$post = $check;

		if ( self::STATUS_ARCHIVED !== $post->post_status ) {
			return new \WP_Error( 'orgahb_not_archived', __( 'Only archived content can be restored.', 'orgahb-manager' ) );
		}

		if ( ! user_can( $user_id, 'approve_orgahb_content' ) ) {
			return new \WP_Error( 'orgahb_no_permission', __( 'You do not have permission to restore content.', 'orgahb-manager' ) );
		}

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		delete_post_meta( $post_id, '_orgahb_archived_reason' );

		ORGAHB_Audit_Log::write(
			ORGAHB_Audit_Log::EVT_RESTORED,
			$post_id,
			$user_id,
			array(
				'from_status' => self::STATUS_ARCHIVED,
				'to_status'   => 'draft',
			)
		);

		return true;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Validates that a post exists and belongs to an applicable CPT.
	 *
	 * @param int $post_id
	 * @return \WP_Post|\WP_Error
	 */
	private static function validate_post( int $post_id ): \WP_Post|\WP_Error {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'orgahb_post_not_found', __( 'Post not found.', 'orgahb-manager' ) );
		}

		if ( ! in_array( $post->post_type, self::APPLICABLE_TYPES, true ) ) {
			return new \WP_Error(
				'orgahb_wrong_type',
				__( 'Workflow transitions are not applicable to this content type.', 'orgahb-manager' )
			);
		}

		return $post;
	}

	/**
	 * Returns the ID of the most recent revision for a post, or 0 if none.
	 *
	 * @param int $post_id
	 * @return int
	 */
	private static function current_revision_id( int $post_id ): int {
		$revisions = wp_get_post_revisions(
			$post_id,
			array( 'posts_per_page' => 1, 'fields' => 'ids' )
		);

		return ! empty( $revisions ) ? (int) array_key_first( $revisions ) : 0;
	}
}
