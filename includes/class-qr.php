<?php
/**
 * QR code token generation and lookup.
 *
 * The QR token (_orgahb_qr_token) is immutable once assigned to a building:
 * it is never regenerated, as existing printed QR codes would break.
 *
 * This class only manages the token string and the landing URL.
 * Actual QR image generation happens client-side via the qrcode JS library.
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * QR token management and landing URL generation.
 */
final class ORGAHB_QR {

	/** Post meta key that stores the immutable QR token. */
	const META_KEY = '_orgahb_qr_token';

	/**
	 * URL path segment between the base URL and the token.
	 * Full pattern: {base_url}b/{token}
	 */
	const PATH_SEGMENT = 'b/';

	// ── Token management ──────────────────────────────────────────────────────

	/**
	 * Returns the existing token for a building, generating and persisting
	 * one if none exists yet.
	 *
	 * Tokens are immutable — once generated they are never replaced.
	 *
	 * @param int $building_id  orgahb_building post ID.
	 * @return string           UUID v4 token.
	 */
	public static function ensure_token( int $building_id ): string {
		$existing = self::get_token( $building_id );
		if ( '' !== $existing ) {
			return $existing;
		}

		$token = wp_generate_uuid4();
		update_post_meta( $building_id, self::META_KEY, $token );
		return $token;
	}

	/**
	 * Retrieves the stored token for a building without generating one.
	 *
	 * @param int $building_id
	 * @return string  Token string, or empty string if none assigned yet.
	 */
	public static function get_token( int $building_id ): string {
		return (string) get_post_meta( $building_id, self::META_KEY, true );
	}

	/**
	 * Finds the building ID associated with a given QR token.
	 *
	 * Uses a meta query so the lookup is index-backed.
	 *
	 * @param string $token
	 * @return int|null  Building post ID, or null if not found.
	 */
	public static function find_building( string $token ): ?int {
		$token = sanitize_text_field( $token );
		if ( '' === $token ) {
			return null;
		}

		$posts = get_posts(
			array(
				'post_type'      => 'orgahb_building',
				'post_status'    => array( 'publish', 'draft' ),
				'meta_key'       => self::META_KEY,
				'meta_value'     => $token,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : null;
	}

	// ── URL generation ────────────────────────────────────────────────────────

	/**
	 * Returns the canonical landing URL for a given QR token.
	 *
	 * Format: {orgahb_qr_base_url}b/{token}
	 *
	 * @param string $token
	 * @return string  Absolute URL.
	 */
	public static function landing_url( string $token ): string {
		return ORGAHB_Settings::qr_base_url() . self::PATH_SEGMENT . rawurlencode( $token );
	}

	/**
	 * Returns the landing URL for a building, calling ensure_token() first.
	 *
	 * @param int $building_id
	 * @return string
	 */
	public static function building_url( int $building_id ): string {
		return self::landing_url( self::ensure_token( $building_id ) );
	}
}
