<?php
/**
 * Plugin settings via the WordPress Settings API.
 *
 * Registers settings groups on admin_init so they are stored via the
 * WordPress Options API.  All other classes should read settings through
 * the static typed getters here rather than calling get_option() directly.
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Settings registration and typed option getters.
 */
final class ORGAHB_Settings {

	/** Settings group used on the plugin settings page. */
	const GROUP = 'orgahb_settings';

	// ── Option keys ───────────────────────────────────────────────────────────

	const OPT_DELETE_DATA        = 'orgahb_delete_data_on_uninstall';
	const OPT_REMINDER_DAYS      = 'orgahb_review_reminder_days';
	const OPT_REQUIRE_COMMENT    = 'orgahb_require_reviewer_comment';
	const OPT_QR_BASE_URL        = 'orgahb_qr_base_url';

	/**
	 * Hook registration — called from ORGAHB_Plugin::init_components().
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
	}

	/**
	 * Registers all plugin settings with the WordPress Settings API.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			self::GROUP,
			self::OPT_DELETE_DATA,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting(
			self::GROUP,
			self::OPT_REMINDER_DAYS,
			array(
				'type'              => 'integer',
				'default'           => 14,
				'sanitize_callback' => 'absint',
			)
		);

		register_setting(
			self::GROUP,
			self::OPT_REQUIRE_COMMENT,
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		register_setting(
			self::GROUP,
			self::OPT_QR_BASE_URL,
			array(
				'type'              => 'string',
				'default'           => home_url( '/' ),
				'sanitize_callback' => 'esc_url_raw',
			)
		);
	}

	// ── Typed getters ─────────────────────────────────────────────────────────

	/**
	 * Whether the admin has opted in to deleting all plugin data on uninstall.
	 * Off by default — data deletion must be an explicit choice.
	 *
	 * @return bool
	 */
	public static function delete_data_on_uninstall(): bool {
		return (bool) get_option( self::OPT_DELETE_DATA, false );
	}

	/**
	 * Number of days before a review date at which review-reminder
	 * notifications should fire.
	 *
	 * @return int
	 */
	public static function review_reminder_days(): int {
		return max( 1, (int) get_option( self::OPT_REMINDER_DAYS, 14 ) );
	}

	/**
	 * Whether a reviewer must supply a comment when returning content
	 * for revision.
	 *
	 * @return bool
	 */
	public static function require_reviewer_comment(): bool {
		return (bool) get_option( self::OPT_REQUIRE_COMMENT, true );
	}

	/**
	 * Base URL used when generating building QR code landing links.
	 * Trailing slash guaranteed.
	 *
	 * @return string
	 */
	public static function qr_base_url(): string {
		$url = (string) get_option( self::OPT_QR_BASE_URL, '' );
		return trailingslashit( $url ?: home_url( '/' ) );
	}
}
