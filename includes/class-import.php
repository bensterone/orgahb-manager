<?php
/**
 * CSV import for buildings and building bundle links (spec §36.3 Ring B).
 *
 * Two import types handled via the admin_post action `orgahb_import_csv`:
 *
 * 1. Buildings CSV — creates new building posts.
 *    Required columns: title
 *    Optional columns: building_code, address, areas (JSON string or
 *                      pipe-separated "key:Label" pairs)
 *    Rows whose title already matches an existing building slug are skipped.
 *
 * 2. Building links CSV — links existing content to existing buildings.
 *    Required columns: building, content_type, content
 *    Optional columns: area_key, sort_order, is_featured, local_note,
 *                      advisory_interval_label
 *    Both `building` and `content` columns accept a post ID, slug, or title.
 *    Rows that fail resolution are recorded as errors.
 *
 * Both imports redirect back to the Backup & Export page with a result
 * summary in the query string (created, skipped, errors counts).
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * CSV import handler for buildings and building bundle links.
 */
final class ORGAHB_Import {

	// ── Hook registration ─────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_orgahb_import_csv', array( self::class, 'handle_import' ) );
	}

	// ── Admin-post handler ────────────────────────────────────────────────────

	/**
	 * Validates, parses, and runs the uploaded CSV import.
	 *
	 * @return void
	 */
	public static function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'orgahb-manager' ), '', array( 'response' => 403 ) );
		}

		if ( ! check_admin_referer( 'orgahb_import_csv' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'orgahb-manager' ), '', array( 'response' => 403 ) );
		}

		$type = sanitize_key( $_POST['import_type'] ?? '' );
		if ( ! in_array( $type, array( 'buildings', 'building_links' ), true ) ) {
			self::redirect_with( array( 'import_error' => 'invalid_type' ) );
		}

		if ( empty( $_FILES['orgahb_csv_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['orgahb_csv_file']['tmp_name'] ) ) {
			self::redirect_with( array( 'import_error' => 'no_file' ) );
		}

		$path = $_FILES['orgahb_csv_file']['tmp_name'];
		$rows = self::parse_csv( $path );

		if ( is_wp_error( $rows ) ) {
			self::redirect_with( array( 'import_error' => urlencode( $rows->get_error_message() ) ) );
		}

		if ( 'buildings' === $type ) {
			$result = self::import_buildings( $rows );
		} else {
			$result = self::import_building_links( $rows );
		}

		self::redirect_with( array(
			'import_type'    => $type,
			'import_created' => $result['created'],
			'import_skipped' => $result['skipped'],
			'import_errors'  => $result['errors'],
		) );
	}

	// ── CSV parser ────────────────────────────────────────────────────────────

	/**
	 * Parses a CSV file into an array of associative rows using the first row
	 * as column headers.
	 *
	 * @param string $path Absolute path to the uploaded temp file.
	 * @return array[]|WP_Error  Rows array, or WP_Error on failure.
	 */
	private static function parse_csv( string $path ): array|WP_Error {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			return new WP_Error( 'csv_open', __( 'Could not open the uploaded file.', 'orgahb-manager' ) );
		}

		// Strip UTF-8 BOM if present.
		$bom = fread( $fh, 3 );
		if ( "\xEF\xBB\xBF" !== $bom ) {
			rewind( $fh );
		}

		$headers = fgetcsv( $fh );
		if ( ! $headers ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fh );
			return new WP_Error( 'csv_empty', __( 'The CSV file is empty or has no header row.', 'orgahb-manager' ) );
		}

		// Normalise headers: lowercase, trim, replace spaces with underscores.
		$headers = array_map(
			fn( $h ) => str_replace( ' ', '_', strtolower( trim( $h ) ) ),
			$headers
		);

		$rows = array();
		while ( ( $values = fgetcsv( $fh ) ) !== false ) {
			if ( count( $values ) < count( $headers ) ) {
				$values = array_pad( $values, count( $headers ), '' );
			}
			$rows[] = array_combine( $headers, array_map( 'trim', $values ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fh );
		return $rows;
	}

	// ── Buildings import ──────────────────────────────────────────────────────

	/**
	 * Imports buildings from parsed CSV rows.
	 *
	 * Expected columns: title (required), building_code, address, areas
	 *
	 * The `areas` column accepts:
	 *   - a JSON array of area objects (same shape as META_AREAS)
	 *   - a pipe-separated string of "key:Label" pairs, e.g. "heating:Heating Room|basement:Basement"
	 *
	 * @param array[] $rows
	 * @return array{ created: int, skipped: int, errors: int }
	 */
	private static function import_buildings( array $rows ): array {
		$created = 0;
		$skipped = 0;
		$errors  = 0;

		foreach ( $rows as $row ) {
			$title = sanitize_text_field( $row['title'] ?? '' );
			if ( '' === $title ) {
				$errors++;
				continue;
			}

			// Skip if a building with this title already exists.
			$existing = get_page_by_title( $title, OBJECT, 'orgahb_building' );
			if ( $existing ) {
				$skipped++;
				continue;
			}

			$post_id = wp_insert_post( array(
				'post_title'  => $title,
				'post_type'   => 'orgahb_building',
				'post_status' => 'publish',
			), true );

			if ( is_wp_error( $post_id ) ) {
				$errors++;
				continue;
			}

			// Optional meta.
			if ( ! empty( $row['building_code'] ) ) {
				update_post_meta( $post_id, ORGAHB_Buildings::META_CODE, sanitize_text_field( $row['building_code'] ) );
			}
			if ( ! empty( $row['address'] ) ) {
				update_post_meta( $post_id, ORGAHB_Buildings::META_ADDRESS, sanitize_text_field( $row['address'] ) );
			}

			// Areas: JSON or pipe-separated key:Label pairs.
			if ( ! empty( $row['areas'] ) ) {
				$areas = self::parse_areas_column( $row['areas'] );
				if ( ! empty( $areas ) ) {
					ORGAHB_Buildings::save_areas( $post_id, $areas );
				}
			}

			$created++;
		}

		return array( 'created' => $created, 'skipped' => $skipped, 'errors' => $errors );
	}

	/**
	 * Parses the `areas` CSV column into an array of area definitions.
	 *
	 * Accepts:
	 *   - JSON string: '[{"key":"heating","label":"Heating Room"},...]'
	 *   - Pipe-separated: "heating:Heating Room|basement:Basement"
	 *
	 * @param string $raw
	 * @return list<array<string, mixed>>
	 */
	private static function parse_areas_column( string $raw ): array {
		$raw = trim( $raw );

		// Try JSON first.
		if ( str_starts_with( $raw, '[' ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		// Pipe-separated key:Label pairs.
		$areas  = array();
		$sort   = 0;
		foreach ( explode( '|', $raw ) as $pair ) {
			$parts = explode( ':', $pair, 2 );
			$key   = sanitize_key( trim( $parts[0] ) );
			$label = sanitize_text_field( trim( $parts[1] ?? $parts[0] ) );
			if ( $key ) {
				$areas[] = array(
					'key'         => $key,
					'label'       => $label ?: ucfirst( $key ),
					'sort_order'  => $sort++,
					'description' => '',
				);
			}
		}

		return $areas;
	}

	// ── Building links import ─────────────────────────────────────────────────

	/**
	 * Imports building bundle links from parsed CSV rows.
	 *
	 * Expected columns:
	 *   building      (required) — post ID, slug, or title of the building
	 *   content_type  (required) — page | process | document
	 *   content       (required) — post ID, slug, or title of the content item
	 *   area_key      (optional, default: general)
	 *   sort_order    (optional, default: 0)
	 *   is_featured   (optional, default: 0) — 1/true/yes
	 *   local_note    (optional)
	 *   advisory_interval_label (optional)
	 *
	 * @param array[] $rows
	 * @return array{ created: int, skipped: int, errors: int }
	 */
	private static function import_building_links( array $rows ): array {
		$created = 0;
		$skipped = 0;
		$errors  = 0;

		foreach ( $rows as $row ) {
			$building_ref  = trim( $row['building']     ?? '' );
			$content_type  = sanitize_key( $row['content_type'] ?? '' );
			$content_ref   = trim( $row['content']      ?? '' );

			if ( '' === $building_ref || '' === $content_type || '' === $content_ref ) {
				$errors++;
				continue;
			}

			if ( ! in_array( $content_type, ORGAHB_Building_Links::CONTENT_TYPES, true ) ) {
				$errors++;
				continue;
			}

			$building_id = self::resolve_post( $building_ref, 'orgahb_building' );
			if ( ! $building_id ) {
				$errors++;
				continue;
			}

			$cpt        = ORGAHB_Building_Links::CPT_MAP[ $content_type ];
			$content_id = self::resolve_post( $content_ref, $cpt );
			if ( ! $content_id ) {
				$errors++;
				continue;
			}

			$area_key = sanitize_key( $row['area_key'] ?? '' ) ?: 'general';

			$opts = array(
				'sort_order'  => isset( $row['sort_order'] ) ? (int) $row['sort_order'] : 0,
				'is_featured' => self::truthy( $row['is_featured'] ?? '' ),
			);
			if ( ! empty( $row['local_note'] ) ) {
				$opts['local_note'] = sanitize_text_field( $row['local_note'] );
			}
			if ( ! empty( $row['advisory_interval_label'] ) ) {
				$opts['advisory_interval_label'] = sanitize_text_field( $row['advisory_interval_label'] );
			}

			$link_id = ORGAHB_Building_Links::add( $building_id, $area_key, $content_type, $content_id, $opts );
			if ( $link_id ) {
				$created++;
			} else {
				$skipped++;
			}
		}

		return array( 'created' => $created, 'skipped' => $skipped, 'errors' => $errors );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Resolves a post reference (numeric ID, slug, or title) to a post ID.
	 *
	 * Returns 0 if the post is not found or is not of the expected type.
	 *
	 * @param string $ref       Numeric ID, slug, or title.
	 * @param string $post_type Expected post type.
	 * @return int  Post ID or 0.
	 */
	private static function resolve_post( string $ref, string $post_type ): int {
		// Numeric ID.
		if ( ctype_digit( $ref ) ) {
			$post = get_post( (int) $ref );
			return ( $post && $post->post_type === $post_type ) ? $post->ID : 0;
		}

		// Slug.
		$by_slug = get_page_by_path( $ref, OBJECT, $post_type );
		if ( $by_slug ) {
			return $by_slug->ID;
		}

		// Title.
		$by_title = get_page_by_title( $ref, OBJECT, $post_type );
		return ( $by_title && $by_title->post_type === $post_type ) ? $by_title->ID : 0;
	}

	/**
	 * Returns true for common truthy CSV values (1, true, yes, on).
	 *
	 * @param string $val
	 * @return bool
	 */
	private static function truthy( string $val ): bool {
		return in_array( strtolower( trim( $val ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Redirects back to the Backup & Export page with result query args.
	 *
	 * @param array<string, mixed> $args
	 * @return never
	 */
	private static function redirect_with( array $args ): never {
		$url = add_query_arg(
			$args,
			admin_url( 'admin.php?page=orgahb-backup-export' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
