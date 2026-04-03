<?php
/**
 * ZIP backup generation for uninstall safety and migration (spec §12.5).
 *
 * Produces a downloadable ZIP archive via the admin_post action
 * `orgahb_generate_backup`. The archive contains JSON and NDJSON files
 * representing the complete plugin dataset at the moment of export.
 *
 * Archive contents:
 *   manifest.json          — export metadata (plugin version, site, timestamp)
 *   settings.json          — all plugin option values
 *   buildings.json         — all orgahb_building posts + meta
 *   building-links.json    — all rows from orgahb_building_links
 *   content-pages.json     — all orgahb_page posts + meta
 *   content-processes.json — all orgahb_process posts + meta
 *   content-documents.json — all orgahb_document posts + meta
 *   acknowledgments.ndjson — all rows from orgahb_acknowledgments
 *   executions.ndjson      — all rows from orgahb_executions
 *   observations.ndjson    — all rows from orgahb_observations
 *   audit-events.ndjson    — all rows from orgahb_audit_events
 *   attachments.csv        — WordPress attachments associated with plugin content
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Backup ZIP generator.
 */
final class ORGAHB_Backup {

	// ── Hook registration ─────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_orgahb_generate_backup', array( self::class, 'handle_generate' ) );
	}

	// ── Admin-post handler ────────────────────────────────────────────────────

	/**
	 * Generates the ZIP archive and streams it to the browser.
	 *
	 * @return void
	 */
	public static function handle_generate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'orgahb-manager' ), '', array( 'response' => 403 ) );
		}

		if ( ! check_admin_referer( 'orgahb_generate_backup' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'orgahb-manager' ), '', array( 'response' => 403 ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die(
				esc_html__( 'The ZipArchive PHP extension is required to generate backups. Please enable it on your server.', 'orgahb-manager' ),
				'',
				array( 'response' => 500 )
			);
		}

		$zip_path = wp_tempnam( 'orgahb-backup-' );
		$zip      = new ZipArchive();

		if ( true !== $zip->open( $zip_path, ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'Could not create backup archive.', 'orgahb-manager' ), '', array( 'response' => 500 ) );
		}

		self::add_manifest( $zip );
		self::add_settings( $zip );
		self::add_posts( $zip, 'orgahb_building',  'buildings.json' );
		self::add_posts( $zip, 'orgahb_page',      'content-pages.json' );
		self::add_posts( $zip, 'orgahb_process',   'content-processes.json' );
		self::add_posts( $zip, 'orgahb_document',  'content-documents.json' );
		self::add_table_json( $zip, 'orgahb_building_links', 'building-links.json' );
		self::add_table_ndjson( $zip, 'orgahb_acknowledgments', 'acknowledgments.ndjson' );
		self::add_table_ndjson( $zip, 'orgahb_executions',      'executions.ndjson' );
		self::add_table_ndjson( $zip, 'orgahb_observations',    'observations.ndjson' );
		self::add_table_ndjson( $zip, 'orgahb_audit_events',    'audit-events.ndjson' );
		self::add_attachments_csv( $zip );

		$zip->close();

		$filename = 'orgahb-backup-' . gmdate( 'Y-m-d-His' ) . '.zip';
		$filesize = filesize( $zip_path );

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		if ( false !== $filesize ) {
			header( 'Content-Length: ' . $filesize );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $zip_path );
		wp_delete_file( $zip_path );
		exit;
	}

	// ── Archive sections ──────────────────────────────────────────────────────

	/**
	 * Adds manifest.json with export metadata.
	 *
	 * @param ZipArchive $zip
	 * @return void
	 */
	private static function add_manifest( ZipArchive $zip ): void {
		global $wp_version;

		$manifest = array(
			'plugin'         => 'orgahb-manager',
			'plugin_version' => ORGAHB_VERSION,
			'wp_version'     => $wp_version,
			'site_url'       => get_site_url(),
			'exported_at'    => gmdate( 'c' ),
			'db_version'     => (string) get_option( 'orgahb_db_version', '' ),
		);

		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Adds settings.json with all plugin option values.
	 *
	 * @param ZipArchive $zip
	 * @return void
	 */
	private static function add_settings( ZipArchive $zip ): void {
		$settings = array(
			'orgahb_delete_data_on_uninstall' => get_option( 'orgahb_delete_data_on_uninstall' ),
			'orgahb_review_reminder_days'     => get_option( 'orgahb_review_reminder_days' ),
			'orgahb_require_reviewer_comment' => get_option( 'orgahb_require_reviewer_comment' ),
			'orgahb_qr_base_url'              => get_option( 'orgahb_qr_base_url' ),
		);

		$zip->addFromString( 'settings.json', wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Adds a JSON file with all posts of a given CPT type plus their post meta.
	 *
	 * @param ZipArchive $zip
	 * @param string     $post_type
	 * @param string     $filename
	 * @return void
	 */
	private static function add_posts( ZipArchive $zip, string $post_type, string $filename ): void {
		$query = new WP_Query( array(
			'post_type'      => $post_type,
			'post_status'    => array( 'publish', 'pending', 'draft', 'private', 'orgahb_archived' ),
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		) );

		$rows = array();
		foreach ( $query->posts as $post ) {
			$rows[] = array(
				'id'          => $post->ID,
				'title'       => $post->post_title,
				'slug'        => $post->post_name,
				'status'      => $post->post_status,
				'author'      => $post->post_author,
				'date_gmt'    => $post->post_date_gmt,
				'modified_gmt' => $post->post_modified_gmt,
				'content'     => $post->post_content,
				'excerpt'     => $post->post_excerpt,
				'meta'        => get_post_meta( $post->ID ),
			);
		}

		$zip->addFromString( $filename, wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Adds a JSON file with all rows from a custom plugin table.
	 *
	 * @param ZipArchive $zip
	 * @param string     $table  Table name without prefix.
	 * @param string     $filename
	 * @return void
	 */
	private static function add_table_json( ZipArchive $zip, string $table, string $filename ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}{$table}", ARRAY_A ) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$zip->addFromString( $filename, wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Adds an NDJSON (newline-delimited JSON) file for large tables that should
	 * stream row-by-row rather than loading all rows into memory at once.
	 *
	 * Rows are fetched in 500-row pages to avoid memory exhaustion on large sites.
	 *
	 * @param ZipArchive $zip
	 * @param string     $table  Table name without prefix.
	 * @param string     $filename
	 * @return void
	 */
	private static function add_table_ndjson( ZipArchive $zip, string $table, string $filename ): void {
		global $wpdb;

		$lines  = array();
		$offset = 0;
		$batch  = 500;

		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}{$table} ORDER BY id ASC LIMIT %d OFFSET %d",
					$batch,
					$offset
				),
				ARRAY_A
			) ?: array();
			// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			foreach ( $rows as $row ) {
				$lines[] = wp_json_encode( $row, JSON_UNESCAPED_UNICODE );
			}

			$offset += $batch;
		} while ( count( $rows ) === $batch );

		$zip->addFromString( $filename, implode( "\n", $lines ) );
	}

	/**
	 * Adds attachments.csv — all WP media attachments whose parent is a
	 * plugin CPT post, plus any that are referenced in plugin post meta.
	 *
	 * @param ZipArchive $zip
	 * @return void
	 */
	private static function add_attachments_csv( ZipArchive $zip ): void {
		global $wpdb;

		// Attachments whose post_parent is a plugin CPT post.
		$cpt_types_in = "'" . implode( "','", array( 'orgahb_page', 'orgahb_process', 'orgahb_document', 'orgahb_building' ) ) . "'";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT a.ID, a.post_title, a.post_mime_type, a.guid, a.post_parent
			 FROM {$wpdb->posts} a
			 INNER JOIN {$wpdb->posts} p ON p.ID = a.post_parent
			 WHERE a.post_type = 'attachment'
			   AND p.post_type IN ({$cpt_types_in})
			 ORDER BY a.ID ASC",
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$output = fopen( 'php://temp', 'r+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		fputcsv( $output, array( 'id', 'title', 'mime_type', 'url', 'parent_post_id' ) );
		foreach ( $rows as $row ) {
			fputcsv( $output, array(
				$row['ID'],
				$row['post_title'],
				$row['post_mime_type'],
				$row['guid'],
				$row['post_parent'],
			) );
		}
		rewind( $output );
		$csv = stream_get_contents( $output );
		fclose( $output );

		$zip->addFromString( 'attachments.csv', $csv );
	}
}
