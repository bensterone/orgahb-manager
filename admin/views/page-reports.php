<?php
/**
 * Admin view: Reports.
 *
 * Renders filter controls and a data table for any of the six baseline
 * report types (spec §31.2). Provides CSV download and print-view links.
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// ── Resolve request params ─────────────────────────────────────────────────

$report_types = array(
	'acknowledgments'   => __( 'Acknowledgments', 'orgahb-manager' ),
	'executions'        => __( 'Field Evidence', 'orgahb-manager' ),
	'observations'      => __( 'Observations', 'orgahb-manager' ),
	'bundle_mapping'    => __( 'Bundle Mapping', 'orgahb-manager' ),
	'content_inventory' => __( 'Content Inventory', 'orgahb-manager' ),
	'review_dates'      => __( 'Review Dates', 'orgahb-manager' ),
);

$active_type = sanitize_key( $_GET['report_type'] ?? 'acknowledgments' );
if ( ! isset( $report_types[ $active_type ] ) ) {
	$active_type = 'acknowledgments';
}

$building_id = absint( $_GET['building_id'] ?? 0 );
$date_from   = sanitize_text_field( $_GET['date_from'] ?? '' );
$date_to     = sanitize_text_field( $_GET['date_to'] ?? '' );
$do_run      = isset( $_GET['run'] );

$headers = array();
$rows    = array();
$run_error = '';

if ( $do_run ) {
	$filters = ORGAHB_Export::build_filters( $building_id, $date_from, $date_to );
	[ $headers, $rows ] = ORGAHB_Export::get_report_data( $active_type, $filters );
}

// Buildings list for the filter drop-down.
$buildings = get_posts( array(
	'post_type'      => 'orgahb_building',
	'post_status'    => 'publish',
	'posts_per_page' => -1,
	'no_found_rows'  => true,
	'orderby'        => 'title',
	'order'          => 'ASC',
) );

// ── Export URLs ────────────────────────────────────────────────────────────

$base_args = array(
	'action'      => 'orgahb_export_csv',
	'type'        => $active_type,
	'building_id' => $building_id,
	'date_from'   => $date_from,
	'date_to'     => $date_to,
);
$csv_url = wp_nonce_url(
	add_query_arg( $base_args, admin_url( 'admin-post.php' ) ),
	'orgahb_export_csv'
);

$print_args         = $base_args;
$print_args['action'] = 'orgahb_print_report';
$print_url = wp_nonce_url(
	add_query_arg( $print_args, admin_url( 'admin-post.php' ) ),
	'orgahb_print_report'
);
?>
<div class="wrap orgahb-reports-wrap">
	<h1><?php esc_html_e( 'Handbook Reports', 'orgahb-manager' ); ?></h1>

	<?php /* Report type tab strip */ ?>
	<nav class="orgahb-report-tabs" aria-label="<?php esc_attr_e( 'Report types', 'orgahb-manager' ); ?>">
		<?php foreach ( $report_types as $slug => $label ) :
			$tab_url = add_query_arg( array( 'page' => 'orgahb-reports', 'report_type' => $slug ), admin_url( 'admin.php' ) );
		?>
		<a href="<?php echo esc_url( $tab_url ); ?>"
		   class="orgahb-report-tab<?php echo $slug === $active_type ? ' is-active' : ''; ?>">
			<?php echo esc_html( $label ); ?>
		</a>
		<?php endforeach; ?>
	</nav>

	<?php /* Filter form */ ?>
	<form method="get" class="orgahb-report-filters">
		<input type="hidden" name="page" value="orgahb-reports">
		<input type="hidden" name="report_type" value="<?php echo esc_attr( $active_type ); ?>">
		<input type="hidden" name="run" value="1">

		<div class="orgahb-filter-row">
			<?php if ( in_array( $active_type, array( 'acknowledgments', 'executions', 'observations', 'bundle_mapping' ), true ) ) : ?>
			<label>
				<?php esc_html_e( 'Building', 'orgahb-manager' ); ?>
				<select name="building_id">
					<option value=""><?php esc_html_e( '— All buildings —', 'orgahb-manager' ); ?></option>
					<?php foreach ( $buildings as $b ) : ?>
					<option value="<?php echo esc_attr( $b->ID ); ?>" <?php selected( $building_id, $b->ID ); ?>>
						<?php echo esc_html( $b->post_title ); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php endif; ?>

			<?php if ( in_array( $active_type, array( 'acknowledgments', 'executions', 'observations', 'review_dates' ), true ) ) : ?>
			<label>
				<?php esc_html_e( 'From', 'orgahb-manager' ); ?>
				<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
			</label>
			<label>
				<?php esc_html_e( 'To', 'orgahb-manager' ); ?>
				<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
			</label>
			<?php endif; ?>

			<button type="submit" class="button button-primary">
				<?php esc_html_e( 'Run Report', 'orgahb-manager' ); ?>
			</button>
		</div>
	</form>

	<?php if ( $do_run ) : ?>

	<div class="orgahb-report-actions">
		<a href="<?php echo esc_url( $csv_url ); ?>" class="button">
			<?php esc_html_e( 'Download CSV', 'orgahb-manager' ); ?>
		</a>
		<a href="<?php echo esc_url( $print_url ); ?>" class="button" target="_blank">
			<?php esc_html_e( 'Print / PDF', 'orgahb-manager' ); ?>
		</a>
		<span class="orgahb-report-count">
			<?php printf(
				/* translators: %d: number of rows */
				esc_html( _n( '%d row', '%d rows', count( $rows ), 'orgahb-manager' ) ),
				count( $rows )
			); ?>
		</span>
	</div>

	<?php if ( empty( $rows ) ) : ?>
		<p class="orgahb-report-empty"><?php esc_html_e( 'No data found for the selected filters.', 'orgahb-manager' ); ?></p>
	<?php else : ?>
		<div class="orgahb-report-table-wrap">
			<table class="wp-list-table widefat fixed striped orgahb-report-table">
				<thead>
					<tr>
						<?php foreach ( $headers as $h ) : ?>
						<th><?php echo esc_html( $h ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
					<tr>
						<?php foreach ( $row as $cell ) : ?>
						<td><?php echo esc_html( (string) $cell ); ?></td>
						<?php endforeach; ?>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<?php else : ?>
		<p class="orgahb-report-prompt">
			<?php esc_html_e( 'Select your filters and click Run Report.', 'orgahb-manager' ); ?>
		</p>
	<?php endif; ?>
</div>
