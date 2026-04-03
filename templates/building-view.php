<?php
/**
 * Front-end template: building handbook landing page.
 *
 * Loaded by ORGAHB_CPT::load_building_template() when the `orgahb_qr_token`
 * query var is set (i.e. a visitor hits the QR short URL: /b/{uuid}).
 *
 * Responsibilities:
 *  - Resolve the QR token to a building post.
 *  - Guard: 404 when token unknown; login redirect when unauthenticated.
 *  - Enqueue handbook-viewer JS bundle and CSS.
 *  - Localise window.orgahbHandbookConfig for the React app.
 *  - Output the WordPress page shell (header → mount div → footer).
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// ── 1. Resolve the QR token ───────────────────────────────────────────────────

$qr_token = sanitize_text_field( get_query_var( 'orgahb_qr_token', '' ) );

if ( '' === $qr_token ) {
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	get_template_part( 404 );
	exit;
}

$building_id = ORGAHB_QR::find_building( $qr_token );

if ( ! $building_id ) {
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	get_template_part( 404 );
	exit;
}

// ── 2. Authentication guard ───────────────────────────────────────────────────

if ( ! is_user_logged_in() ) {
	wp_redirect( wp_login_url( home_url( '/b/' . rawurlencode( $qr_token ) ) ) );
	exit;
}

if ( ! current_user_can( 'read_orgahb_handbook' ) ) {
	wp_die(
		esc_html__( 'You do not have permission to view this building handbook.', 'orgahb-manager' ),
		esc_html__( 'Access Denied', 'orgahb-manager' ),
		array( 'response' => 403 )
	);
}

// ── 3. Enqueue assets ─────────────────────────────────────────────────────────

$dist_url = ORGAHB_PLUGIN_URL . 'assets/dist/';
$ver      = ORGAHB_VERSION;

// Core WP dependencies for the REST nonce middleware.
wp_enqueue_script( 'wp-api-fetch' );
wp_add_inline_script(
	'wp-api-fetch',
	sprintf(
		'wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( %s ) );',
		wp_json_encode( wp_create_nonce( 'wp_rest' ) )
	),
	'after'
);

// Handbook viewer bundle — React comes from wp-element (WP's bundled React).
wp_enqueue_script(
	'orgahb-handbook-viewer',
	$dist_url . 'handbook-viewer.js',
	array( 'wp-element', 'wp-api-fetch', 'wp-i18n' ),
	$ver,
	true   // load in footer
);

wp_enqueue_style(
	'orgahb-handbook-viewer',
	ORGAHB_PLUGIN_URL . 'assets/css/handbook-viewer.css',
	array(),
	$ver
);

// ── 4. Localise configuration ─────────────────────────────────────────────────

$current_user = wp_get_current_user();

wp_localize_script(
	'orgahb-handbook-viewer',
	'orgahbHandbookConfig',
	array(
		'buildingId'  => $building_id,
		'token'       => $qr_token,
		'restUrl'     => rest_url( 'orgahb/v1/' ),
		'nonce'           => wp_create_nonce( 'wp_rest' ),
		'pdfjsWorkerUrl'  => ORGAHB_PLUGIN_URL . 'assets/pdfjs/pdf.worker.min.mjs',
		'currentUser' => array(
			'id'          => get_current_user_id(),
			'displayName' => $current_user->display_name,
			'canAck'      => current_user_can( 'acknowledge_orgahb_content' ),
			'canLog'      => current_user_can( 'log_orgahb_process_step' ),
			'canObserve'  => current_user_can( 'create_orgahb_observation' ),
		),
	)
);

// ── 5. Output ─────────────────────────────────────────────────────────────────

get_header();
?>
<main id="orgahb-handbook-viewer-page" class="orgahb-hv-page">
	<div id="orgahb-handbook-viewer">
		<?php
		// Server-rendered fallback: visible before JS hydrates and for no-JS.
		$building_post = get_post( $building_id );
		if ( $building_post ) :
			$building_code    = ORGAHB_Buildings::get_code( $building_id );
			$building_address = ORGAHB_Buildings::get_address( $building_id );
		?>
		<noscript>
			<div class="orgahb-hv-nojs-notice">
				<h1><?php echo esc_html( $building_post->post_title ); ?></h1>
				<?php if ( $building_code ) : ?>
					<p><?php echo esc_html( $building_code ); ?></p>
				<?php endif; ?>
				<?php if ( $building_address ) : ?>
					<p><?php echo esc_html( $building_address ); ?></p>
				<?php endif; ?>
				<p><?php esc_html_e( 'JavaScript is required to view the building handbook.', 'orgahb-manager' ); ?></p>
			</div>
		</noscript>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
