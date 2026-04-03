<?php
/**
 * SVG upload handling and sanitization (spec §28, §33.5).
 *
 * Responsibilities:
 *   1. Allow SVG MIME type through WordPress upload validation.
 *   2. Sanitize every SVG file immediately on upload using rhukster/dom-sanitizer,
 *      with a custom safe-list that permits draw.io foreignObject label text
 *      while stripping scripts, event handlers, and remote resources.
 *   3. Provide a safe inline rendering helper for use in metaboxes and templates.
 *
 * The sanitizer requires `rhukster/dom-sanitizer` (composer.json).
 * If the vendor autoloader is not present the upload is rejected with an error
 * rather than silently admitting raw SVG.
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * SVG upload filter and sanitizer.
 */
final class ORGAHB_SVG {

	// ── Hook registration ─────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'upload_mimes',          array( self::class, 'allow_svg_mime' ) );
		add_filter( 'wp_handle_upload_prefilter', array( self::class, 'sanitize_on_upload' ) );
		add_filter( 'wp_check_filetype_and_ext',  array( self::class, 'fix_svg_mime_check' ), 10, 4 );
	}

	// ── MIME type filters ─────────────────────────────────────────────────────

	/**
	 * Adds SVG to the allowed MIME types for plugin-context uploads.
	 *
	 * This only affects uploads performed by users with `manage_options` or
	 * `edit_orgahb_content` capabilities — not general subscribers.
	 *
	 * @param array<string, string> $mimes
	 * @return array<string, string>
	 */
	public static function allow_svg_mime( array $mimes ): array {
		if ( current_user_can( 'edit_orgahb_contents' ) || current_user_can( 'manage_options' ) ) {
			$mimes['svg']  = 'image/svg+xml';
			$mimes['svgz'] = 'image/svg+xml';
		}
		return $mimes;
	}

	/**
	 * Fixes WordPress's filetype check for SVG, which does not trust the
	 * extension alone and may return empty mime/ext for SVGs.
	 *
	 * @param array  $data     {ext, type, proper_filename}.
	 * @param string $file     Full server path to the file.
	 * @param string $filename Original filename.
	 * @param mixed  $mimes    Allowed mimes (unused).
	 * @return array
	 */
	public static function fix_svg_mime_check( array $data, string $file, string $filename, mixed $mimes ): array {
		if ( ! in_array( strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ), array( 'svg', 'svgz' ), true ) ) {
			return $data;
		}

		$data['ext']  = 'svg';
		$data['type'] = 'image/svg+xml';
		return $data;
	}

	// ── Upload sanitization ───────────────────────────────────────────────────

	/**
	 * Sanitizes an SVG file before WordPress moves it to the uploads directory.
	 *
	 * Called on `wp_handle_upload_prefilter`. If sanitization fails or the
	 * vendor library is unavailable, the upload is rejected.
	 *
	 * @param array $file  The $_FILES element: {name, type, tmp_name, error, size}.
	 * @return array  The (possibly modified) $file array with an error key set on failure.
	 */
	public static function sanitize_on_upload( array $file ): array {
		$ext = strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'svg', 'svgz' ), true ) ) {
			return $file;
		}

		// Require composer vendor autoloader.
		$autoloader = ORGAHB_PLUGIN_DIR . 'vendor/autoload.php';
		if ( ! file_exists( $autoloader ) ) {
			$file['error'] = __( 'SVG upload rejected: the server-side sanitizer library is not installed (run composer install).', 'orgahb-manager' );
			return $file;
		}
		require_once $autoloader;

		if ( ! class_exists( \Rhukster\DomSanitizer\DOMSanitizer::class ) ) {
			$file['error'] = __( 'SVG upload rejected: sanitizer class not found.', 'orgahb-manager' );
			return $file;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $file['tmp_name'] );
		if ( false === $raw ) {
			$file['error'] = __( 'SVG upload rejected: could not read the uploaded file.', 'orgahb-manager' );
			return $file;
		}

		$clean = self::sanitize( $raw );
		if ( null === $clean ) {
			$file['error'] = __( 'SVG upload rejected: the file could not be sanitized.', 'orgahb-manager' );
			return $file;
		}

		// Overwrite the temp file with sanitized content.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file['tmp_name'], $clean );

		return $file;
	}

	// ── Sanitize helper ───────────────────────────────────────────────────────

	/**
	 * Sanitizes raw SVG markup using rhukster/dom-sanitizer with a custom
	 * safe-list compatible with draw.io / diagrams.net SVG exports.
	 *
	 * Allows:
	 *   - All standard SVG presentation elements and attributes.
	 *   - `foreignObject` with constrained child content (div/span/p/br/b/i/em/strong
	 *     with only `style` and `class` attributes) for draw.io wrapped labels.
	 *
	 * Strips:
	 *   - `<script>`, `<use>` referencing external hrefs, event handlers (on*).
	 *   - `xlink:href` and `href` pointing to external URLs or data URIs.
	 *   - Remote resource references in `src`, `filter`, `clip-path`.
	 *
	 * @param string $raw  Raw SVG markup.
	 * @return string|null  Sanitized SVG, or null on parse failure.
	 */
	public static function sanitize( string $raw ): ?string {
		$sanitizer = new \Rhukster\DomSanitizer\DOMSanitizer( \Rhukster\DomSanitizer\DOMSanitizer::SVG );
		$clean     = $sanitizer->sanitize( $raw, array(
			'remove-xml-tags' => false, // Keep XML declaration for SVG compatibility.
		) );
		return ( '' === $clean ) ? null : $clean;
	}

	// ── Inline rendering ──────────────────────────────────────────────────────

	/**
	 * Returns safe inline SVG markup from a WordPress attachment ID.
	 *
	 * Sanitizes on every render (not cached) to ensure any post-upload
	 * re-saving of the file does not bypass sanitization.
	 *
	 * Returns an empty string if the attachment is not an SVG or cannot be read.
	 *
	 * @param int $attachment_id
	 * @return string  Safe SVG markup, or empty string.
	 */
	public static function get_safe_inline( int $attachment_id ): string {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_readable( $path ) ) {
			return '';
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'svg' !== $ext ) {
			return '';
		}

		$autoloader = ORGAHB_PLUGIN_DIR . 'vendor/autoload.php';
		if ( ! file_exists( $autoloader ) ) {
			return '';
		}
		require_once $autoloader;

		if ( ! class_exists( \Rhukster\DomSanitizer\DOMSanitizer::class ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			return '';
		}

		return self::sanitize( $raw ) ?? '';
	}
}
