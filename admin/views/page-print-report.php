<?php
/**
 * Admin view: Print-optimised report.
 *
 * Served by ORGAHB_Export::handle_print_report() as a standalone HTML page
 * (outside the WP admin shell) for print / browser-PDF use (spec §31.3).
 * Data is passed via $GLOBALS set by the handler.
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$type    = (string) ( $GLOBALS['orgahb_report_type']    ?? '' );
$headers = (array)  ( $GLOBALS['orgahb_report_headers'] ?? array() );
$rows    = (array)  ( $GLOBALS['orgahb_report_rows']    ?? array() );
$filters = (array)  ( $GLOBALS['orgahb_report_filters'] ?? array() );

$type_labels = array(
	'acknowledgments'   => __( 'Acknowledgments', 'orgahb-manager' ),
	'executions'        => __( 'Field Evidence', 'orgahb-manager' ),
	'observations'      => __( 'Observations', 'orgahb-manager' ),
	'bundle_mapping'    => __( 'Bundle Mapping', 'orgahb-manager' ),
	'content_inventory' => __( 'Content Inventory', 'orgahb-manager' ),
	'review_dates'      => __( 'Review Dates', 'orgahb-manager' ),
);

$title = $type_labels[ $type ] ?? $type;

$meta_parts = array();
if ( ! empty( $filters['building_id'] ) ) {
	$b = get_post( (int) $filters['building_id'] );
	if ( $b ) {
		$meta_parts[] = esc_html__( 'Building', 'orgahb-manager' ) . ': ' . esc_html( $b->post_title );
	}
}
if ( ! empty( $filters['date_from'] ) ) {
	$meta_parts[] = esc_html__( 'From', 'orgahb-manager' ) . ': ' . esc_html( $filters['date_from'] );
}
if ( ! empty( $filters['date_to'] ) ) {
	$meta_parts[] = esc_html__( 'To', 'orgahb-manager' ) . ': ' . esc_html( $filters['date_to'] );
}
$meta_parts[] = esc_html__( 'Generated', 'orgahb-manager' ) . ': ' . esc_html( gmdate( 'Y-m-d H:i' ) . ' UTC' );
$meta_parts[] = esc_html( count( $rows ) ) . ' ' . esc_html( _n( 'row', 'rows', count( $rows ), 'orgahb-manager' ) );

?><!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
<meta charset="utf-8">
<title><?php echo esc_html( get_bloginfo( 'name' ) . ' — ' . $title ); ?></title>
<style>
	* { box-sizing: border-box; margin: 0; padding: 0; }
	body { font-family: -apple-system, Arial, sans-serif; font-size: 11px; color: #000; background: #fff; }
	.report-header { padding: 16px 20px 10px; border-bottom: 2px solid #000; }
	.report-header h1 { font-size: 16px; margin-bottom: 4px; }
	.report-meta { color: #555; font-size: 10px; }
	.report-meta span { margin-right: 16px; }
	table { width: 100%; border-collapse: collapse; margin-top: 12px; }
	thead th { background: #f0f0f0; border: 1px solid #ccc; padding: 4px 6px; font-size: 10px; text-align: left; }
	tbody td { border: 1px solid #ddd; padding: 3px 6px; vertical-align: top; word-break: break-word; }
	tbody tr:nth-child(even) td { background: #f9f9f9; }
	.report-empty { padding: 20px; text-align: center; color: #666; }
	.no-print { text-align: center; padding: 12px; }
	.no-print button { padding: 8px 20px; font-size: 13px; cursor: pointer; }
	@media print {
		.no-print { display: none; }
		thead { display: table-header-group; }
	}
</style>
</head>
<body>

<div class="no-print">
	<button onclick="window.print()">🖨 <?php esc_html_e( 'Print / Save as PDF', 'orgahb-manager' ); ?></button>
</div>

<div class="report-header">
	<h1><?php echo esc_html( get_bloginfo( 'name' ) . ' — ' . $title ); ?></h1>
	<p class="report-meta">
		<?php foreach ( $meta_parts as $part ) : ?>
		<span><?php echo $part; // already escaped above ?></span>
		<?php endforeach; ?>
	</p>
</div>

<?php if ( empty( $rows ) ) : ?>
	<p class="report-empty"><?php esc_html_e( 'No data for the selected filters.', 'orgahb-manager' ); ?></p>
<?php else : ?>
	<table>
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
<?php endif; ?>

</body>
</html>
