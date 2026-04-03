<?php
/**
 * Admin view: Backup & Export.
 *
 * Provides:
 *   - ZIP backup download (JSON/NDJSON, spec §12.5)
 *   - CSV report downloads (spec §31)
 *   - CSV import for buildings and building links (spec §36.3 Ring B)
 *   - Database table reference
 *   - Uninstall safety notice
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$report_labels = array(
	'acknowledgments'   => __( 'Acknowledgments', 'orgahb-manager' ),
	'executions'        => __( 'Field Evidence', 'orgahb-manager' ),
	'observations'      => __( 'Observations', 'orgahb-manager' ),
	'bundle_mapping'    => __( 'Bundle Mapping', 'orgahb-manager' ),
	'content_inventory' => __( 'Content Inventory', 'orgahb-manager' ),
	'review_dates'      => __( 'Review Dates', 'orgahb-manager' ),
);

$delete_on_uninstall = ORGAHB_Settings::delete_data_on_uninstall();

// Import result notices from a previous redirect.
$import_type    = sanitize_key( $_GET['import_type']    ?? '' );
$import_created = isset( $_GET['import_created'] ) ? (int) $_GET['import_created'] : null;
$import_skipped = isset( $_GET['import_skipped'] ) ? (int) $_GET['import_skipped'] : null;
$import_errors  = isset( $_GET['import_errors']  ) ? (int) $_GET['import_errors']  : null;
$import_error   = sanitize_text_field( wp_unslash( $_GET['import_error'] ?? '' ) );
?>
<div class="wrap orgahb-backup-wrap">
	<h1><?php esc_html_e( 'Backup & Export', 'orgahb-manager' ); ?></h1>

	<p><?php esc_html_e(
		'Download CSV exports of all operational data before migrating or uninstalling the plugin. These exports are point-in-time snapshots — they do not replace a database backup.',
		'orgahb-manager'
	); ?></p>

	<?php if ( $delete_on_uninstall ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<strong><?php esc_html_e( 'Warning:', 'orgahb-manager' ); ?></strong>
			<?php esc_html_e(
				'"Delete all plugin data on uninstall" is currently enabled in Settings. Download your backups before deactivating and deleting the plugin.',
				'orgahb-manager'
			); ?>
		</p>
	</div>
	<?php endif; ?>

	<?php if ( null !== $import_created ) : ?>
	<div class="notice notice-success inline is-dismissible">
		<p>
			<?php
			$type_label = 'buildings' === $import_type ? __( 'Buildings', 'orgahb-manager' ) : __( 'Building links', 'orgahb-manager' );
			printf(
				/* translators: 1: import type label 2: created 3: skipped 4: errors */
				esc_html__( '%1$s import complete — created: %2$d, skipped: %3$d, errors: %4$d.', 'orgahb-manager' ),
				esc_html( $type_label ),
				(int) $import_created,
				(int) $import_skipped,
				(int) $import_errors
			);
			?>
		</p>
	</div>
	<?php endif; ?>

	<?php if ( $import_error ) : ?>
	<div class="notice notice-error inline">
		<p>
			<strong><?php esc_html_e( 'Import error:', 'orgahb-manager' ); ?></strong>
			<?php echo esc_html( $import_error ); ?>
		</p>
	</div>
	<?php endif; ?>

	<!-- ── Backup ── -->
	<h2><?php esc_html_e( 'Full Backup Archive', 'orgahb-manager' ); ?></h2>
	<p><?php esc_html_e(
		'Download a complete ZIP backup of all plugin data as JSON and NDJSON files. Use this before migrating or uninstalling. Requires ZipArchive (PHP extension).',
		'orgahb-manager'
	); ?></p>
	<p>
		<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=orgahb_generate_backup' ), 'orgahb_generate_backup' ) ); ?>"
		   class="button button-primary">
			<?php esc_html_e( 'Download Backup ZIP', 'orgahb-manager' ); ?>
		</a>
	</p>

	<hr>

	<!-- ── CSV exports ── -->
	<h2><?php esc_html_e( 'Data Exports', 'orgahb-manager' ); ?></h2>
	<table class="wp-list-table widefat fixed striped" style="max-width:640px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Report', 'orgahb-manager' ); ?></th>
				<th><?php esc_html_e( 'Download', 'orgahb-manager' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $report_labels as $type => $label ) :
				$csv_url = wp_nonce_url(
					add_query_arg(
						array( 'action' => 'orgahb_export_csv', 'type' => $type, 'building_id' => 0, 'date_from' => '', 'date_to' => '' ),
						admin_url( 'admin-post.php' )
					),
					'orgahb_export_csv'
				);
			?>
			<tr>
				<td><?php echo esc_html( $label ); ?></td>
				<td>
					<a href="<?php echo esc_url( $csv_url ); ?>" class="button button-small">
						<?php esc_html_e( 'Download CSV', 'orgahb-manager' ); ?>
					</a>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<hr>

	<!-- ── CSV imports ── -->
	<h2><?php esc_html_e( 'CSV Import', 'orgahb-manager' ); ?></h2>
	<p><?php esc_html_e(
		'Import buildings or bundle links in bulk by uploading a CSV file. The first row must be a header row. Existing items are skipped (not updated).',
		'orgahb-manager'
	); ?></p>

	<div style="display:flex;gap:32px;flex-wrap:wrap;">

		<!-- Buildings import -->
		<div style="flex:1;min-width:260px;max-width:480px;">
			<h3><?php esc_html_e( 'Import Buildings', 'orgahb-manager' ); ?></h3>
			<p class="description">
				<?php esc_html_e(
					'Required column: title. Optional: building_code, address, areas (JSON array or pipe-separated key:Label pairs, e.g. "heating:Heating Room|basement:Basement").',
					'orgahb-manager'
				); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action"      value="orgahb_import_csv">
				<input type="hidden" name="import_type" value="buildings">
				<?php wp_nonce_field( 'orgahb_import_csv' ); ?>
				<input type="file" name="orgahb_csv_file" accept=".csv,text/csv" required style="display:block;margin-bottom:8px;">
				<button type="submit" class="button">
					<?php esc_html_e( 'Import Buildings', 'orgahb-manager' ); ?>
				</button>
			</form>
		</div>

		<!-- Building links import -->
		<div style="flex:1;min-width:260px;max-width:480px;">
			<h3><?php esc_html_e( 'Import Building Links', 'orgahb-manager' ); ?></h3>
			<p class="description">
				<?php esc_html_e(
					'Required columns: building (ID/slug/title), content_type (page|process|document), content (ID/slug/title). Optional: area_key, sort_order, is_featured (1/0), local_note, advisory_interval_label.',
					'orgahb-manager'
				); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action"      value="orgahb_import_csv">
				<input type="hidden" name="import_type" value="building_links">
				<?php wp_nonce_field( 'orgahb_import_csv' ); ?>
				<input type="file" name="orgahb_csv_file" accept=".csv,text/csv" required style="display:block;margin-bottom:8px;">
				<button type="submit" class="button">
					<?php esc_html_e( 'Import Building Links', 'orgahb-manager' ); ?>
				</button>
			</form>
		</div>

	</div>

	<hr>

	<!-- ── DB tables ── -->
	<h2><?php esc_html_e( 'Database Tables', 'orgahb-manager' ); ?></h2>
	<p><?php esc_html_e(
		'The plugin creates the following custom database tables. A full database backup (mysqldump or your host\'s backup tool) is the authoritative backup method.',
		'orgahb-manager'
	); ?></p>
	<?php global $wpdb; ?>
	<ul style="list-style:disc;margin-left:2em;">
		<?php foreach ( array( 'orgahb_acknowledgments', 'orgahb_executions', 'orgahb_observations', 'orgahb_audit_events', 'orgahb_building_links' ) as $t ) : ?>
		<li><code><?php echo esc_html( $wpdb->prefix . $t ); ?></code></li>
		<?php endforeach; ?>
	</ul>

	<hr>

	<!-- ── Settings link ── -->
	<h2><?php esc_html_e( 'Settings', 'orgahb-manager' ); ?></h2>
	<p>
		<?php printf(
			/* translators: %s: settings page link */
			esc_html__( 'Uninstall behaviour is controlled from the %s page.', 'orgahb-manager' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=orgahb-settings' ) ) . '">' . esc_html__( 'Settings', 'orgahb-manager' ) . '</a>'
		); ?>
	</p>
</div>
