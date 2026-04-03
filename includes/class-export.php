<?php
/**
 * Report and export engine (spec §31).
 *
 * Provides six baseline reports:
 *   1. Acknowledgments
 *   2. Field evidence (executions)
 *   3. Observations
 *   4. Building bundle mapping
 *   5. Content inventory
 *   6. Review dates
 *
 * Each report can be retrieved as a PHP array (for HTML rendering) or streamed
 * directly as a CSV download via the `admin_post_orgahb_export_csv` action.
 *
 * A print-optimised HTML export is served via `admin_post_orgahb_print_report`.
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Report data fetching and CSV/HTML export.
 */
final class ORGAHB_Export {

	/** Allowed report type slugs. */
	const REPORT_TYPES = array(
		'acknowledgments',
		'executions',
		'observations',
		'bundle_mapping',
		'content_inventory',
		'review_dates',
	);

	// ── Hook registration ─────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_orgahb_export_csv',   array( self::class, 'handle_csv_download' ) );
		add_action( 'admin_post_orgahb_print_report', array( self::class, 'handle_print_report' ) );
	}

	// ── Admin-post handlers ───────────────────────────────────────────────────

	/**
	 * Streams a CSV download. Linked from the Reports page via a signed URL.
	 *
	 * @return void
	 */
	public static function handle_csv_download(): void {
		if ( ! current_user_can( 'publish_orgahb_contents' ) ) {
			wp_die( esc_html__( 'Access denied.', 'orgahb-manager' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'orgahb_export_csv' );

		$type        = sanitize_key( $_GET['type'] ?? '' );
		$building_id = absint( $_GET['building_id'] ?? 0 );
		$date_from   = sanitize_text_field( $_GET['date_from'] ?? '' );
		$date_to     = sanitize_text_field( $_GET['date_to'] ?? '' );

		if ( ! in_array( $type, self::REPORT_TYPES, true ) ) {
			wp_die( esc_html__( 'Unknown report type.', 'orgahb-manager' ) );
		}

		$filters = self::build_filters( $building_id, $date_from, $date_to );
		[ $headers, $rows ] = self::get_report_data( $type, $filters );

		$filename = 'orgahb-' . $type . '-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM for Excel.
		fputcsv( $out, $headers );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Serves a print-optimised HTML report page.
	 *
	 * @return void
	 */
	public static function handle_print_report(): void {
		if ( ! current_user_can( 'publish_orgahb_contents' ) ) {
			wp_die( esc_html__( 'Access denied.', 'orgahb-manager' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'orgahb_print_report' );

		$type        = sanitize_key( $_GET['type'] ?? '' );
		$building_id = absint( $_GET['building_id'] ?? 0 );
		$date_from   = sanitize_text_field( $_GET['date_from'] ?? '' );
		$date_to     = sanitize_text_field( $_GET['date_to'] ?? '' );

		if ( ! in_array( $type, self::REPORT_TYPES, true ) ) {
			wp_die( esc_html__( 'Unknown report type.', 'orgahb-manager' ) );
		}

		$filters = self::build_filters( $building_id, $date_from, $date_to );
		[ $headers, $rows ] = self::get_report_data( $type, $filters );

		// Expose to the print template.
		$GLOBALS['orgahb_report_type']    = $type;
		$GLOBALS['orgahb_report_headers'] = $headers;
		$GLOBALS['orgahb_report_rows']    = $rows;
		$GLOBALS['orgahb_report_filters'] = $filters;

		require_once ORGAHB_PLUGIN_DIR . 'admin/views/page-print-report.php';
		exit;
	}

	// ── Public report data API ────────────────────────────────────────────────

	/**
	 * Returns [ $headers, $rows ] for a given report type.
	 *
	 * @param string               $type
	 * @param array<string, mixed> $filters
	 * @return array{ 0: string[], 1: list<list<string>> }
	 */
	public static function get_report_data( string $type, array $filters = [] ): array {
		switch ( $type ) {
			case 'acknowledgments':   return self::report_acknowledgments( $filters );
			case 'executions':        return self::report_executions( $filters );
			case 'observations':      return self::report_observations( $filters );
			case 'bundle_mapping':    return self::report_bundle_mapping( $filters );
			case 'content_inventory': return self::report_content_inventory( $filters );
			case 'review_dates':      return self::report_review_dates( $filters );
			default:                  return [ [], [] ];
		}
	}

	/**
	 * Builds a normalised filter array from raw user inputs.
	 *
	 * @param int    $building_id
	 * @param string $date_from   Y-m-d or empty.
	 * @param string $date_to     Y-m-d or empty.
	 * @return array<string, mixed>
	 */
	public static function build_filters( int $building_id, string $date_from, string $date_to ): array {
		return array(
			'building_id' => $building_id,
			'date_from'   => self::validate_date( $date_from ),
			'date_to'     => self::validate_date( $date_to ),
		);
	}

	// ── Report 1: Acknowledgments ─────────────────────────────────────────────

	private static function report_acknowledgments( array $f ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $f['date_from'] ) ) {
			$where[]  = 'a.acknowledged_at >= %s';
			$params[] = $f['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $f['date_to'] ) ) {
			$where[]  = 'a.acknowledged_at <= %s';
			$params[] = $f['date_to'] . ' 23:59:59';
		}
		if ( ! empty( $f['building_id'] ) ) {
			$where[]  = "EXISTS (
				SELECT 1 FROM {$wpdb->prefix}orgahb_building_links bl
				WHERE bl.content_id = a.post_id AND bl.building_id = %d
			)";
			$params[] = (int) $f['building_id'];
		}

		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT a.id, a.post_id, p.post_title,
		               a.user_id, COALESCE(u.display_name, a.historical_user_label) AS user_name,
		               a.post_revision_id, a.post_version_label, a.source, a.acknowledged_at
		        FROM {$wpdb->prefix}orgahb_acknowledgments a
		        LEFT JOIN {$wpdb->posts} p ON p.ID = a.post_id
		        LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
		        WHERE {$where_sql}
		        ORDER BY a.acknowledged_at DESC LIMIT 2000";

		$rows_raw = empty( $params )
			? $wpdb->get_results( $sql, ARRAY_A ) // phpcs:ignore
			: $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore

		$headers = array( 'ID', 'Post ID', 'Content Title', 'User ID', 'User', 'Revision ID', 'Version', 'Source', 'Acknowledged At' );
		$out     = array();
		foreach ( $rows_raw ?: array() as $r ) {
			$out[] = array( $r['id'], $r['post_id'], $r['post_title'] ?? '', $r['user_id'] ?? '', $r['user_name'] ?? '', $r['post_revision_id'], $r['post_version_label'] ?? '', $r['source'], $r['acknowledged_at'] );
		}
		return [ $headers, $out ];
	}

	// ── Report 2: Executions ──────────────────────────────────────────────────

	private static function report_executions( array $f ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $f['building_id'] ) ) {
			$where[]  = 'e.building_id = %d';
			$params[] = (int) $f['building_id'];
		}
		if ( ! empty( $f['date_from'] ) ) {
			$where[]  = 'e.executed_at >= %s';
			$params[] = $f['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $f['date_to'] ) ) {
			$where[]  = 'e.executed_at <= %s';
			$params[] = $f['date_to'] . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT e.id, e.post_id, p.post_title,
		               e.building_id, b.post_title AS building_title,
		               e.area_key, e.hotspot_id, e.outcome, e.note,
		               e.user_id, COALESCE(u.display_name, e.historical_user_label) AS user_name,
		               e.post_revision_id, e.post_version_label, e.source, e.executed_at
		        FROM {$wpdb->prefix}orgahb_executions e
		        LEFT JOIN {$wpdb->posts} p ON p.ID = e.post_id
		        LEFT JOIN {$wpdb->posts} b ON b.ID = e.building_id
		        LEFT JOIN {$wpdb->users} u ON u.ID = e.user_id
		        WHERE {$where_sql}
		        ORDER BY e.executed_at DESC LIMIT 2000";

		$rows_raw = empty( $params )
			? $wpdb->get_results( $sql, ARRAY_A ) // phpcs:ignore
			: $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore

		$headers = array( 'ID', 'Process ID', 'Process', 'Building ID', 'Building', 'Area', 'Hotspot ID', 'Outcome', 'Note', 'User ID', 'User', 'Revision ID', 'Version', 'Source', 'Executed At' );
		$out     = array();
		foreach ( $rows_raw ?: array() as $r ) {
			$out[] = array( $r['id'], $r['post_id'], $r['post_title'] ?? '', $r['building_id'], $r['building_title'] ?? '', $r['area_key'] ?? '', $r['hotspot_id'], $r['outcome'], $r['note'] ?? '', $r['user_id'] ?? '', $r['user_name'] ?? '', $r['post_revision_id'], $r['post_version_label'] ?? '', $r['source'], $r['executed_at'] );
		}
		return [ $headers, $out ];
	}

	// ── Report 3: Observations ────────────────────────────────────────────────

	private static function report_observations( array $f ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $f['building_id'] ) ) {
			$where[]  = 'o.building_id = %d';
			$params[] = (int) $f['building_id'];
		}
		if ( ! empty( $f['date_from'] ) ) {
			$where[]  = 'o.created_at >= %s';
			$params[] = $f['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $f['date_to'] ) ) {
			$where[]  = 'o.created_at <= %s';
			$params[] = $f['date_to'] . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT o.id, o.building_id, b.post_title AS building_title,
		               o.area_key, o.category, o.status, o.summary, o.details,
		               o.external_reference,
		               o.author_user_id, COALESCE(u.display_name, o.historical_author_label) AS author_name,
		               o.created_at, o.resolved_at
		        FROM {$wpdb->prefix}orgahb_observations o
		        LEFT JOIN {$wpdb->posts} b ON b.ID = o.building_id
		        LEFT JOIN {$wpdb->users} u ON u.ID = o.author_user_id
		        WHERE {$where_sql}
		        ORDER BY o.created_at DESC LIMIT 2000";

		$rows_raw = empty( $params )
			? $wpdb->get_results( $sql, ARRAY_A ) // phpcs:ignore
			: $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore

		$headers = array( 'ID', 'Building ID', 'Building', 'Area', 'Category', 'Status', 'Summary', 'Details', 'External Ref', 'Author ID', 'Author', 'Created At', 'Resolved At' );
		$out     = array();
		foreach ( $rows_raw ?: array() as $r ) {
			$out[] = array( $r['id'], $r['building_id'], $r['building_title'] ?? '', $r['area_key'] ?? '', $r['category'], $r['status'], $r['summary'], $r['details'] ?? '', $r['external_reference'] ?? '', $r['author_user_id'] ?? '', $r['author_name'] ?? '', $r['created_at'], $r['resolved_at'] ?? '' );
		}
		return [ $headers, $out ];
	}

	// ── Report 4: Bundle mapping ──────────────────────────────────────────────

	private static function report_bundle_mapping( array $f ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $f['building_id'] ) ) {
			$where[]  = 'bl.building_id = %d';
			$params[] = (int) $f['building_id'];
		}

		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT bl.id, bl.building_id, b.post_title AS building_title,
		               bl.area_key, bl.content_type, bl.content_id,
		               p.post_title AS content_title, p.post_status,
		               bl.sort_order, bl.is_featured, bl.local_note, bl.advisory_interval_label
		        FROM {$wpdb->prefix}orgahb_building_links bl
		        LEFT JOIN {$wpdb->posts} b ON b.ID = bl.building_id
		        LEFT JOIN {$wpdb->posts} p ON p.ID = bl.content_id
		        WHERE {$where_sql}
		        ORDER BY b.post_title ASC, bl.area_key ASC, bl.sort_order ASC
		        LIMIT 5000";

		$rows_raw = empty( $params )
			? $wpdb->get_results( $sql, ARRAY_A ) // phpcs:ignore
			: $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore

		$headers = array( 'Link ID', 'Building ID', 'Building', 'Area', 'Content Type', 'Content ID', 'Content Title', 'Status', 'Sort Order', 'Featured', 'Local Note', 'Interval Label' );
		$out     = array();
		foreach ( $rows_raw ?: array() as $r ) {
			$out[] = array( $r['id'], $r['building_id'], $r['building_title'] ?? '', $r['area_key'], $r['content_type'], $r['content_id'], $r['content_title'] ?? '', $r['post_status'] ?? '', $r['sort_order'], $r['is_featured'] ? 'yes' : 'no', $r['local_note'] ?? '', $r['advisory_interval_label'] ?? '' );
		}
		return [ $headers, $out ];
	}

	// ── Report 5: Content inventory ───────────────────────────────────────────

	private static function report_content_inventory( array $f ): array {
		$headers = array( 'ID', 'Type', 'Title', 'Status', 'Version', 'Valid From', 'Valid Until', 'Requires Ack', 'Owner Label', 'Next Review', 'Modified' );
		$out     = array();

		foreach ( ORGAHB_Building_Links::CPT_MAP as $content_type => $cpt ) {
			$posts = get_posts( array(
				'post_type'      => $cpt,
				'post_status'    => array( 'publish', 'draft', 'orgahb_archived' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'title',
				'order'          => 'ASC',
			) );

			foreach ( $posts as $post ) {
				$id    = $post->ID;
				$out[] = array(
					$id, $content_type, $post->post_title, $post->post_status,
					get_post_meta( $id, ORGAHB_Metaboxes::META_VERSION_LABEL, true ) ?? '',
					get_post_meta( $id, ORGAHB_Metaboxes::META_VALID_FROM, true ) ?? '',
					get_post_meta( $id, ORGAHB_Metaboxes::META_VALID_UNTIL, true ) ?? '',
					get_post_meta( $id, ORGAHB_Metaboxes::META_REQUIRES_ACK, true ) ? 'yes' : 'no',
					get_post_meta( $id, ORGAHB_Buildings::META_OWNER_LABEL, true ) ?? '',
					get_post_meta( $id, ORGAHB_Buildings::META_NEXT_REVIEW, true ) ?? '',
					$post->post_modified,
				);
			}
		}

		return [ $headers, $out ];
	}

	// ── Report 6: Review dates ────────────────────────────────────────────────

	private static function report_review_dates( array $f ): array {
		$headers = array( 'ID', 'Type', 'Title', 'Status', 'Version', 'Owner Label', 'Next Review Date' );
		$out     = array();

		$type_map = array_merge(
			ORGAHB_Building_Links::CPT_MAP,
			array( 'building' => 'orgahb_building' )
		);

		foreach ( $type_map as $content_type => $cpt ) {
			$posts = get_posts( array(
				'post_type'      => $cpt,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_key'       => ORGAHB_Buildings::META_NEXT_REVIEW,
				'meta_value'     => '',
				'meta_compare'   => '!=',
			) );

			foreach ( $posts as $post ) {
				$id     = $post->ID;
				$review = (string) get_post_meta( $id, ORGAHB_Buildings::META_NEXT_REVIEW, true );
				if ( '' === $review ) {
					continue;
				}
				if ( ! empty( $f['date_from'] ) && $review < $f['date_from'] ) {
					continue;
				}
				if ( ! empty( $f['date_to'] ) && $review > $f['date_to'] ) {
					continue;
				}
				$out[] = array(
					$id, $content_type, $post->post_title, $post->post_status,
					get_post_meta( $id, ORGAHB_Metaboxes::META_VERSION_LABEL, true ) ?? '',
					get_post_meta( $id, ORGAHB_Buildings::META_OWNER_LABEL, true ) ?? '',
					$review,
				);
			}
		}

		usort( $out, fn( $a, $b ) => strcmp( (string) $a[6], (string) $b[6] ) );

		return [ $headers, $out ];
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Returns $date if it is a valid Y-m-d string, otherwise empty string.
	 *
	 * @param string $date
	 * @return string
	 */
	private static function validate_date( string $date ): string {
		if ( '' === $date ) {
			return '';
		}
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return ( $d && $d->format( 'Y-m-d' ) === $date ) ? $date : '';
	}
}
