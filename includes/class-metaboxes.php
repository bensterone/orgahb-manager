<?php
/**
 * Registers admin metaboxes for all four CPTs.
 *
 * Metaboxes provided:
 *   orgahb_building  — Building Details, Areas Manager, QR Token, Ownership & Review
 *   orgahb_process   — Diagram Asset, Hotspot JSON, Content Meta
 *   orgahb_document  — Document File, Content Meta
 *   orgahb_page      — Content Meta
 *
 * Each CPT has exactly one primary metabox that outputs the save-nonce.
 * The shared "Content Meta" sidebar box re-uses that same nonce so only one
 * nonce field is emitted per CPT (except orgahb_page which only has the
 * Content Meta box and therefore outputs its own nonce there).
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * CPT metabox registration, rendering, and save handling.
 */
final class ORGAHB_Metaboxes {

	// ── Nonce actions (one per CPT) ───────────────────────────────────────────

	const NONCE_BUILDING = 'orgahb_save_building_meta';
	const NONCE_PROCESS  = 'orgahb_save_process_meta';
	const NONCE_DOCUMENT = 'orgahb_save_document_meta';
	const NONCE_PAGE     = 'orgahb_save_page_meta';

	// ── Shared content meta keys (spec §16.1) — complement ORGAHB_Buildings consts ──

	const META_VERSION_LABEL  = '_orgahb_version_label';
	const META_CHANGE_LOG     = '_orgahb_change_log';
	const META_VALID_FROM     = '_orgahb_valid_from';
	const META_VALID_UNTIL    = '_orgahb_valid_until';
	const META_REQUIRES_ACK   = '_orgahb_requires_ack';
	const META_ARCHIVED_REASON= '_orgahb_archived_reason';

	// ── Process-specific meta keys (spec §16.2) ───────────────────────────────

	const META_PROCESS_IMAGE_ID    = '_orgahb_process_image_id';
	const META_HOTSPOTS_JSON       = '_orgahb_hotspots_json';
	const META_DIAGRAM_NOTATION    = '_orgahb_diagram_notation';
	const META_SOURCE_FORMAT       = '_orgahb_source_format';
	const META_SOURCE_ATTACHMENT_ID= '_orgahb_source_attachment_id';
	const META_IS_FIELD_EXECUTABLE = '_orgahb_is_field_executable';

	// ── Document-specific meta keys (spec §16.3) ─────────────────────────────

	const META_CURRENT_ATTACHMENT_ID  = '_orgahb_current_attachment_id';
	const META_DOCUMENT_MIME          = '_orgahb_document_mime';
	const META_DOCUMENT_SIZE          = '_orgahb_document_size';
	const META_DOCUMENT_DISPLAY_MODE  = '_orgahb_document_display_mode';

	// ── Hook registration ─────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes',         array( self::class, 'register_all' ) );
		add_action( 'save_post',              array( self::class, 'save_all' ), 10, 2 );
		add_action( 'admin_enqueue_scripts',  array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_notices',          array( self::class, 'workflow_admin_notices' ) );

		// Workflow admin_post handlers.
		add_action( 'admin_post_orgahb_workflow', array( self::class, 'handle_workflow_action' ) );
	}

	// ── Metabox registration ──────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function register_all(): void {

		// ── orgahb_building ──────────────────────────────────────────────────

		add_meta_box(
			'orgahb-building-details',
			__( 'Building Details', 'orgahb-manager' ),
			array( self::class, 'render_building_details' ),
			'orgahb_building', 'normal', 'high'
		);
		add_meta_box(
			'orgahb-building-areas',
			__( 'Areas', 'orgahb-manager' ),
			array( self::class, 'render_building_areas' ),
			'orgahb_building', 'normal', 'default'
		);
		add_meta_box(
			'orgahb-building-qr',
			__( 'QR Code', 'orgahb-manager' ),
			array( self::class, 'render_building_qr' ),
			'orgahb_building', 'side', 'high'
		);
		add_meta_box(
			'orgahb-building-meta',
			__( 'Ownership & Review', 'orgahb-manager' ),
			array( self::class, 'render_content_meta' ),
			'orgahb_building', 'side', 'default'
		);

		// ── orgahb_process ───────────────────────────────────────────────────

		add_meta_box(
			'orgahb-process-diagram',
			__( 'Diagram & Hotspots', 'orgahb-manager' ),
			array( self::class, 'render_process_diagram' ),
			'orgahb_process', 'normal', 'high'
		);
		add_meta_box(
			'orgahb-process-meta',
			__( 'Content Meta', 'orgahb-manager' ),
			array( self::class, 'render_content_meta' ),
			'orgahb_process', 'side', 'default'
		);

		// ── orgahb_document ──────────────────────────────────────────────────

		add_meta_box(
			'orgahb-document-file',
			__( 'Document File', 'orgahb-manager' ),
			array( self::class, 'render_document_file' ),
			'orgahb_document', 'normal', 'high'
		);
		add_meta_box(
			'orgahb-document-meta',
			__( 'Content Meta', 'orgahb-manager' ),
			array( self::class, 'render_content_meta' ),
			'orgahb_document', 'side', 'default'
		);

		// ── orgahb_page ──────────────────────────────────────────────────────

		// Pages use the block editor; the Content Meta sidebar box is the only
		// custom metabox and it outputs its own nonce.
		add_meta_box(
			'orgahb-page-meta',
			__( 'Content Meta', 'orgahb-manager' ),
			array( self::class, 'render_content_meta' ),
			'orgahb_page', 'side', 'default'
		);

		// ── Workflow ──────────────────────────────────────────────────────────
		// Shown on all four CPTs; provides submit/approve/return/archive/restore.
		foreach ( array( 'orgahb_building', 'orgahb_process', 'orgahb_document', 'orgahb_page' ) as $cpt ) {
			add_meta_box(
				'orgahb-workflow',
				__( 'Workflow', 'orgahb-manager' ),
				array( self::class, 'render_workflow' ),
				$cpt, 'side', 'high'
			);
		}
	}

	// ── Asset enqueueing ──────────────────────────────────────────────────────

	/**
	 * @param string $hook_suffix
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		if ( ! in_array(
			$screen->post_type,
			array( 'orgahb_building', 'orgahb_process', 'orgahb_document', 'orgahb_page' ),
			true
		) ) {
			return;
		}

		// WordPress media uploader.
		wp_enqueue_media();

		wp_enqueue_style(
			'orgahb-metaboxes',
			ORGAHB_PLUGIN_URL . 'assets/css/metaboxes.css',
			array(),
			ORGAHB_VERSION
		);
		wp_enqueue_script(
			'orgahb-metaboxes',
			ORGAHB_PLUGIN_URL . 'assets/js/metaboxes.js',
			array( 'jquery' ),
			ORGAHB_VERSION,
			true
		);
		wp_localize_script(
			'orgahb-metaboxes',
			'orgahbMetaboxes',
			array(
				'selectImageTitle'  => __( 'Select Diagram Image', 'orgahb-manager' ),
				'useImageButton'    => __( 'Use this image', 'orgahb-manager' ),
				'changeImageButton' => __( 'Change Image', 'orgahb-manager' ),
				'selectFileTitle'   => __( 'Select Document File', 'orgahb-manager' ),
				'useFileButton'     => __( 'Use this file', 'orgahb-manager' ),
				'noFile'            => __( 'No file selected', 'orgahb-manager' ),
			)
		);

		// QR canvas renderer — only on the building edit screen.
		if ( 'orgahb_building' === $screen->post_type ) {
			wp_enqueue_script(
				'orgahb-admin-qr',
				ORGAHB_PLUGIN_URL . 'assets/dist/admin-qr.js',
				array(),
				ORGAHB_VERSION,
				true
			);
		}

		// Process editor React app — only on the process edit screen.
		if ( 'orgahb_process' === $screen->post_type ) {
			$post        = get_post();
			$image_id    = $post ? (int) get_post_meta( $post->ID, self::META_PROCESS_IMAGE_ID, true ) : 0;
			$image_url   = $image_id ? (string) wp_get_attachment_url( $image_id ) : '';
			$hotspot_json = $post ? (string) get_post_meta( $post->ID, self::META_HOTSPOTS_JSON, true ) : '';

			wp_enqueue_script(
				'orgahb-process-editor',
				ORGAHB_PLUGIN_URL . 'assets/dist/process-editor.js',
				array( 'wp-element' ),
				ORGAHB_VERSION,
				true
			);
			wp_enqueue_style(
				'orgahb-process-editor',
				ORGAHB_PLUGIN_URL . 'assets/css/process-editor.css',
				array(),
				ORGAHB_VERSION
			);
			wp_localize_script(
				'orgahb-process-editor',
				'orgahbProcessEditor',
				array(
					'imageUrl'     => $image_url,
					'imageId'      => $image_id,
					'hotspotsJson' => $hotspot_json,
				)
			);
		}
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Render: orgahb_building
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Building Details metabox (also outputs the building save-nonce).
	 *
	 * @param WP_Post $post
	 * @return void
	 */
	public static function render_building_details( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_BUILDING, '_orgahb_building_nonce' );

		$code      = ORGAHB_Buildings::get_code( $post->ID );
		$address   = ORGAHB_Buildings::get_address( $post->ID );
		$contacts  = ORGAHB_Buildings::get_contacts( $post->ID );
		$emergency = ORGAHB_Buildings::get_emergency_notes( $post->ID );
		$active    = ORGAHB_Buildings::is_active( $post->ID );
		?>
		<table class="form-table orgahb-meta-table">
			<tr>
				<th scope="row">
					<label for="orgahb_building_code"><?php esc_html_e( 'Building Code', 'orgahb-manager' ); ?></label>
				</th>
				<td>
					<input type="text" id="orgahb_building_code" name="orgahb_building_code"
					       value="<?php echo esc_attr( $code ); ?>" class="regular-text">
					<p class="description"><?php esc_html_e( 'Short internal reference code (optional).', 'orgahb-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="orgahb_building_address"><?php esc_html_e( 'Address', 'orgahb-manager' ); ?></label>
				</th>
				<td>
					<textarea id="orgahb_building_address" name="orgahb_building_address"
					          rows="4" class="large-text"><?php echo esc_textarea( $address ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="orgahb_building_contacts"><?php esc_html_e( 'Local Contacts', 'orgahb-manager' ); ?></label>
				</th>
				<td>
					<textarea id="orgahb_building_contacts" name="orgahb_building_contacts"
					          rows="4" class="large-text"><?php echo esc_textarea( $contacts ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Names, roles, and contact numbers for this building.', 'orgahb-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="orgahb_emergency_notes"><?php esc_html_e( 'Emergency Notes', 'orgahb-manager' ); ?></label>
				</th>
				<td>
					<textarea id="orgahb_emergency_notes" name="orgahb_emergency_notes"
					          rows="4" class="large-text"><?php echo esc_textarea( $emergency ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Quick-reference emergency and safety information.', 'orgahb-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'orgahb-manager' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="orgahb_building_active" value="1" <?php checked( $active ); ?>>
						<?php esc_html_e( 'Building is active', 'orgahb-manager' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Inactive buildings are hidden from field users.', 'orgahb-manager' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Building Areas metabox — manages the orgahb_areas_json meta.
	 *
	 * @param WP_Post $post
	 * @return void
	 */
	public static function render_building_areas( WP_Post $post ): void {
		$areas = ORGAHB_Buildings::get_areas( $post->ID );
		?>
		<p class="description">
			<?php esc_html_e( 'Define the internal areas of this building. The "General" area is permanent and cannot be removed.', 'orgahb-manager' ); ?>
		</p>
		<div id="orgahb-areas-list">
			<?php foreach ( $areas as $i => $area ) :
				$is_general = 'general' === ( $area['key'] ?? '' );
			?>
			<div class="orgahb-area-row">
				<input type="hidden"
				       name="orgahb_areas[<?php echo $i; ?>][sort_order]"
				       value="<?php echo esc_attr( (string) $i ); ?>">
				<input type="text"
				       name="orgahb_areas[<?php echo $i; ?>][key]"
				       value="<?php echo esc_attr( $area['key'] ?? '' ); ?>"
				       placeholder="<?php esc_attr_e( 'slug-key', 'orgahb-manager' ); ?>"
				       class="orgahb-area-key"
				       <?php echo $is_general ? 'readonly' : ''; ?>>
				<input type="text"
				       name="orgahb_areas[<?php echo $i; ?>][label]"
				       value="<?php echo esc_attr( $area['label'] ?? '' ); ?>"
				       placeholder="<?php esc_attr_e( 'Label', 'orgahb-manager' ); ?>"
				       class="orgahb-area-label">
				<input type="text"
				       name="orgahb_areas[<?php echo $i; ?>][description]"
				       value="<?php echo esc_attr( $area['description'] ?? '' ); ?>"
				       placeholder="<?php esc_attr_e( 'Description (optional)', 'orgahb-manager' ); ?>"
				       class="orgahb-area-description">
				<?php if ( ! $is_general ) : ?>
				<button type="button" class="button button-small orgahb-remove-area">
					<?php esc_html_e( 'Remove', 'orgahb-manager' ); ?>
				</button>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
		<p>
			<button type="button" class="button" id="orgahb-add-area">
				<?php esc_html_e( '+ Add Area', 'orgahb-manager' ); ?>
			</button>
		</p>
		<?php
	}

	/**
	 * Building QR Code metabox — read-only display.
	 *
	 * @param WP_Post $post
	 * @return void
	 */
	public static function render_building_qr( WP_Post $post ): void {
		$token = ORGAHB_QR::get_token( $post->ID );

		if ( '' === $token ) {
			echo '<p>' . esc_html__( 'The QR token will be generated the first time you save this building.', 'orgahb-manager' ) . '</p>';
			return;
		}

		$url  = ORGAHB_QR::landing_url( $token );
		$slug = $post->post_name ?: sanitize_title( $post->post_title );
		?>
		<p style="text-align:center;">
			<canvas
				id="orgahb-qr-canvas"
				data-url="<?php echo esc_attr( $url ); ?>"
				data-slug="<?php echo esc_attr( $slug ); ?>"
				style="display:block;margin:0 auto;"
			></canvas>
		</p>
		<p style="text-align:center;">
			<a href="#" id="orgahb-qr-download" class="button button-small">
				<?php esc_html_e( 'Download PNG', 'orgahb-manager' ); ?>
			</a>
		</p>
		<p>
			<strong><?php esc_html_e( 'Landing URL', 'orgahb-manager' ); ?></strong><br>
			<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"
			   style="word-break:break-all;font-size:11px;"><?php echo esc_html( $url ); ?></a>
		</p>
		<p class="description">
			<?php esc_html_e( 'This token is permanent. Printed QR codes remain valid indefinitely.', 'orgahb-manager' ); ?>
		</p>
		<?php
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Render: orgahb_process
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Process Diagram Asset metabox (also outputs the process save-nonce).
	 *
	 * @param WP_Post $post
	 * @return void
	 */
	public static function render_process_diagram( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_PROCESS, '_orgahb_process_nonce' );

		$image_id   = (int) get_post_meta( $post->ID, self::META_PROCESS_IMAGE_ID, true );
		$notation   = (string) get_post_meta( $post->ID, self::META_DIAGRAM_NOTATION, true );
		$src_fmt    = (string) get_post_meta( $post->ID, self::META_SOURCE_FORMAT, true );
		$src_att    = (int) get_post_meta( $post->ID, self::META_SOURCE_ATTACHMENT_ID, true );
		$executable = (bool) get_post_meta( $post->ID, self::META_IS_FIELD_EXECUTABLE, true );
		$json       = (string) get_post_meta( $post->ID, self::META_HOTSPOTS_JSON, true );

		$notation_options = array(
			''               => __( '— select —', 'orgahb-manager' ),
			'flowchart'      => __( 'Flowchart', 'orgahb-manager' ),
			'bpmn_like'      => __( 'BPMN-like', 'orgahb-manager' ),
			'swimlane'       => __( 'Swimlane', 'orgahb-manager' ),
			'check_sequence' => __( 'Check Sequence', 'orgahb-manager' ),
		);
		$format_options = array(
			''              => __( '— select —', 'orgahb-manager' ),
			'svg'           => 'SVG',
			'png'           => 'PNG',
			'jpg'           => 'JPG',
			'drawio_export' => __( 'draw.io export', 'orgahb-manager' ),
		);
		?>
		<table class="form-table orgahb-meta-table">
			<tr>
				<th scope="row">
					<?php esc_html_e( 'Diagram Image', 'orgahb-manager' ); ?>
					<p class="description" style="font-weight:normal;">
						<?php esc_html_e( 'The image displayed to users in the handbook viewer. Select it, then draw rectangles below to mark hotspot areas.', 'orgahb-manager' ); ?>
					</p>
				</th>
				<td>
					<input type="hidden" id="orgahb_process_image_id" name="orgahb_process_image_id"
					       value="<?php echo esc_attr( (string) ( $image_id ?: '' ) ); ?>">
					<div id="orgahb-process-image-preview" class="orgahb-image-preview">
						<?php if ( $image_id ) :
							echo wp_get_attachment_image( $image_id, array( 300, 200 ) );
						endif; ?>
					</div>
					<button type="button" class="button orgahb-upload-image"
					        data-target="orgahb_process_image_id"
					        data-preview="orgahb-process-image-preview">
						<?php echo $image_id
							? esc_html__( 'Change Image', 'orgahb-manager' )
							: esc_html__( 'Select Image', 'orgahb-manager' ); ?>
					</button>
					<?php if ( $image_id ) : ?>
					<button type="button" class="button orgahb-remove-image"
					        data-target="orgahb_process_image_id"
					        data-preview="orgahb-process-image-preview">
						<?php esc_html_e( 'Remove', 'orgahb-manager' ); ?>
					</button>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="orgahb_diagram_notation"><?php esc_html_e( 'Notation', 'orgahb-manager' ); ?></label>
				</th>
				<td>
					<select id="orgahb_diagram_notation" name="orgahb_diagram_notation">
						<?php foreach ( $notation_options as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $notation, $val ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="orgahb_source_format"><?php esc_html_e( 'Source Format', 'orgahb-manager' ); ?></label>
				</th>
				<td>
					<select id="orgahb_source_format" name="orgahb_source_format">
						<?php foreach ( $format_options as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $src_fmt, $val ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<?php esc_html_e( 'Editable Source File', 'orgahb-manager' ); ?>
					<p class="description" style="font-weight:normal;">
						<?php esc_html_e( 'Optional. The original editable file (e.g. .drawio) for future revisions. Not shown to users.', 'orgahb-manager' ); ?>
					</p>
				</th>
				<td>
					<input type="hidden" id="orgahb_source_attachment_id" name="orgahb_source_attachment_id"
					       value="<?php echo esc_attr( (string) ( $src_att ?: '' ) ); ?>">
					<span id="orgahb-source-file-name" class="orgahb-file-name">
						<?php echo $src_att
							? esc_html( get_the_title( $src_att ) )
							: esc_html__( 'No file selected', 'orgahb-manager' ); ?>
					</span>
					<button type="button" class="button orgahb-upload-file"
					        data-target="orgahb_source_attachment_id"
					        data-label="orgahb-source-file-name">
						<?php esc_html_e( 'Upload Source File', 'orgahb-manager' ); ?>
					</button>
					<?php if ( $src_att ) : ?>
					<button type="button" class="button orgahb-remove-file"
					        data-target="orgahb_source_attachment_id"
					        data-label="orgahb-source-file-name">
						<?php esc_html_e( 'Remove', 'orgahb-manager' ); ?>
					</button>
					<?php endif; ?>
					<p class="description">
						<?php esc_html_e( 'Optional: original editable file (e.g. .drawio).', 'orgahb-manager' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Field Executable', 'orgahb-manager' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="orgahb_is_field_executable" value="1" <?php checked( $executable ); ?>>
						<?php esc_html_e( 'Allow field operators to log evidence against hotspots', 'orgahb-manager' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<hr>
		<h3 style="margin: 8px 0 4px;"><?php esc_html_e( 'Hotspot Editor', 'orgahb-manager' ); ?></h3>
		<p class="description" style="margin-bottom:8px;">
			<?php esc_html_e( 'Draw rectangles directly on the diagram to create hotspots. Click a hotspot to edit its label, kind, and target.', 'orgahb-manager' ); ?>
		</p>

		<?php /* Mount point for the React process editor. */ ?>
		<div id="orgahb-process-editor-mount"></div>

		<?php /* Hidden textarea — written to by the React app; submitted as normal form data. */ ?>
		<textarea id="orgahb_hotspots_json" name="orgahb_hotspots_json"
		          rows="10" class="large-text code orgahb-mono"
		          style="display:none;"><?php echo esc_textarea( $json ); ?></textarea>

		<noscript>
			<p class="description">
				<?php esc_html_e(
					'JavaScript is required for the visual hotspot editor.',
					'orgahb-manager'
				); ?>
			</p>
		</noscript>
		<?php
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Render: orgahb_document
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Document File metabox (also outputs the document save-nonce).
	 *
	 * @param WP_Post $post
	 * @return void
	 */
	public static function render_document_file( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_DOCUMENT, '_orgahb_document_nonce' );

		$att_id  = (int) get_post_meta( $post->ID, self::META_CURRENT_ATTACHMENT_ID, true );
		$mode    = (string) get_post_meta( $post->ID, self::META_DOCUMENT_DISPLAY_MODE, true );
		$mime    = (string) get_post_meta( $post->ID, self::META_DOCUMENT_MIME, true );
		$size    = (int) get_post_meta( $post->ID, self::META_DOCUMENT_SIZE, true );

		if ( '' === $mode ) {
			$mode = 'pdf_inline';
		}
		?>
		<table class="form-table orgahb-meta-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'File', 'orgahb-manager' ); ?></th>
				<td>
					<input type="hidden" id="orgahb_current_attachment_id" name="orgahb_current_attachment_id"
					       value="<?php echo esc_attr( (string) ( $att_id ?: '' ) ); ?>">
					<div id="orgahb-document-file-info" class="orgahb-file-info">
						<?php if ( $att_id ) : ?>
							<strong><?php echo esc_html( get_the_title( $att_id ) ); ?></strong>
							<?php if ( $mime ) : ?>
							<span class="orgahb-file-meta"><?php echo esc_html( $mime ); ?></span>
							<?php endif; ?>
							<?php if ( $size ) : ?>
							<span class="orgahb-file-meta"><?php echo esc_html( size_format( $size ) ); ?></span>
							<?php endif; ?>
						<?php else : ?>
							<em><?php esc_html_e( 'No file attached.', 'orgahb-manager' ); ?></em>
						<?php endif; ?>
					</div>
					<button type="button" class="button orgahb-upload-document"
					        data-target="orgahb_current_attachment_id"
					        data-info="orgahb-document-file-info">
						<?php echo $att_id
							? esc_html__( 'Replace File', 'orgahb-manager' )
							: esc_html__( 'Attach File', 'orgahb-manager' ); ?>
					</button>
					<p class="description"><?php esc_html_e( 'Accepted formats: PDF, DOCX.', 'orgahb-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="orgahb_document_display_mode"><?php esc_html_e( 'Display Mode', 'orgahb-manager' ); ?></label>
				</th>
				<td>
					<select id="orgahb_document_display_mode" name="orgahb_document_display_mode">
						<option value="pdf_inline" <?php selected( $mode, 'pdf_inline' ); ?>>
							<?php esc_html_e( 'View inline (PDF)', 'orgahb-manager' ); ?>
						</option>
						<option value="file_open" <?php selected( $mode, 'file_open' ); ?>>
							<?php esc_html_e( 'Open / download (DOCX)', 'orgahb-manager' ); ?>
						</option>
					</select>
					<p class="description">
						<?php esc_html_e( 'DOCX files must use "Open / download".', 'orgahb-manager' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Render: shared Content Meta sidebar (all four CPTs)
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Shared Content Meta sidebar metabox.
	 *
	 * For orgahb_page this box outputs its own nonce (it is the only custom box).
	 * For all other CPTs the nonce is already output by the primary box and is
	 * re-used here — no second nonce field is emitted.
	 *
	 * Fields rendered per CPT:
	 *   all types   : owner, deputy, owner_label, next_review, search_aliases
	 *   content only: version_label, valid_from, valid_until, change_log
	 *   page + doc  : requires_ack
	 *
	 * @param WP_Post $post
	 * @return void
	 */
	public static function render_content_meta( WP_Post $post ): void {
		$type        = $post->post_type;
		$is_building = 'orgahb_building' === $type;
		$is_content  = ! $is_building;
		$needs_ack   = in_array( $type, array( 'orgahb_page', 'orgahb_document' ), true );

		// orgahb_page has no other primary metabox — output nonce here.
		if ( 'orgahb_page' === $type ) {
			wp_nonce_field( self::NONCE_PAGE, '_orgahb_page_nonce' );
		}

		$owner_id    = (int) get_post_meta( $post->ID, ORGAHB_Buildings::META_OWNER_USER_ID, true );
		$deputy_id   = (int) get_post_meta( $post->ID, ORGAHB_Buildings::META_DEPUTY_USER_ID, true );
		$owner_label = (string) get_post_meta( $post->ID, ORGAHB_Buildings::META_OWNER_LABEL, true );
		$next_review = ORGAHB_Buildings::get_next_review( $post->ID );
		$aliases     = (string) get_post_meta( $post->ID, ORGAHB_Buildings::META_SEARCH_ALIASES, true );

		$version     = (string) get_post_meta( $post->ID, self::META_VERSION_LABEL, true );
		$valid_from  = (string) get_post_meta( $post->ID, self::META_VALID_FROM, true );
		$valid_until = (string) get_post_meta( $post->ID, self::META_VALID_UNTIL, true );
		$change_log  = (string) get_post_meta( $post->ID, self::META_CHANGE_LOG, true );
		$req_ack     = (bool) get_post_meta( $post->ID, self::META_REQUIRES_ACK, true );
		?>
		<div class="orgahb-sidebar-meta">
			<?php if ( $is_content ) : ?>
			<p>
				<label for="orgahb_version_label"><strong><?php esc_html_e( 'Version', 'orgahb-manager' ); ?></strong></label><br>
				<input type="text" id="orgahb_version_label" name="orgahb_version_label"
				       value="<?php echo esc_attr( $version ); ?>" class="widefat"
				       placeholder="<?php esc_attr_e( 'e.g. 2.1', 'orgahb-manager' ); ?>">
			</p>
			<p>
				<label for="orgahb_valid_from"><strong><?php esc_html_e( 'Valid From', 'orgahb-manager' ); ?></strong></label><br>
				<input type="date" id="orgahb_valid_from" name="orgahb_valid_from"
				       value="<?php echo esc_attr( $valid_from ); ?>" class="widefat">
			</p>
			<p>
				<label for="orgahb_valid_until"><strong><?php esc_html_e( 'Valid Until', 'orgahb-manager' ); ?></strong></label><br>
				<input type="date" id="orgahb_valid_until" name="orgahb_valid_until"
				       value="<?php echo esc_attr( $valid_until ); ?>" class="widefat">
			</p>
			<?php endif; ?>

			<p>
				<label for="orgahb_next_review"><strong><?php esc_html_e( 'Next Review', 'orgahb-manager' ); ?></strong></label><br>
				<input type="date" id="orgahb_next_review" name="orgahb_next_review"
				       value="<?php echo esc_attr( $next_review ); ?>" class="widefat">
			</p>

			<?php if ( $needs_ack ) : ?>
			<p>
				<label>
					<input type="checkbox" name="orgahb_requires_ack" value="1" <?php checked( $req_ack ); ?>>
					<strong><?php esc_html_e( 'Requires acknowledgment', 'orgahb-manager' ); ?></strong>
				</label>
			</p>
			<?php endif; ?>

			<hr>

			<p>
				<label for="orgahb_owner_user_id"><strong><?php esc_html_e( 'Owner', 'orgahb-manager' ); ?></strong></label><br>
				<?php wp_dropdown_users( array(
					'name'              => 'orgahb_owner_user_id',
					'id'                => 'orgahb_owner_user_id',
					'selected'          => $owner_id ?: 0,
					'show_option_none'  => __( '— none —', 'orgahb-manager' ),
					'option_none_value' => 0,
					'class'             => 'widefat',
				) ); ?>
			</p>
			<p>
				<label for="orgahb_deputy_user_id"><strong><?php esc_html_e( 'Deputy', 'orgahb-manager' ); ?></strong></label><br>
				<?php wp_dropdown_users( array(
					'name'              => 'orgahb_deputy_user_id',
					'id'                => 'orgahb_deputy_user_id',
					'selected'          => $deputy_id ?: 0,
					'show_option_none'  => __( '— none —', 'orgahb-manager' ),
					'option_none_value' => 0,
					'class'             => 'widefat',
				) ); ?>
			</p>
			<p>
				<label for="orgahb_owner_label"><strong><?php esc_html_e( 'Owner Label', 'orgahb-manager' ); ?></strong></label><br>
				<input type="text" id="orgahb_owner_label" name="orgahb_owner_label"
				       value="<?php echo esc_attr( $owner_label ); ?>" class="widefat"
				       placeholder="<?php esc_attr_e( 'Display name when no user is set', 'orgahb-manager' ); ?>">
			</p>
			<p>
				<label for="orgahb_search_aliases"><strong><?php esc_html_e( 'Search Aliases', 'orgahb-manager' ); ?></strong></label><br>
				<input type="text" id="orgahb_search_aliases" name="orgahb_search_aliases"
				       value="<?php echo esc_attr( $aliases ); ?>" class="widefat"
				       placeholder="<?php esc_attr_e( 'comma, separated', 'orgahb-manager' ); ?>">
			</p>

			<?php if ( $is_content ) : ?>
			<hr>
			<p>
				<label for="orgahb_change_log"><strong><?php esc_html_e( 'Change Note', 'orgahb-manager' ); ?></strong></label><br>
				<textarea id="orgahb_change_log" name="orgahb_change_log"
				          rows="3" class="widefat"><?php echo esc_textarea( $change_log ); ?></textarea>
				<span class="description"><?php esc_html_e( 'Brief summary of changes in this revision.', 'orgahb-manager' ); ?></span>
			</p>
			<?php endif; ?>
		</div>
		<?php
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Save dispatch
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * save_post handler — routes to the appropriate CPT save method.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 * @return void
	 */
	public static function save_all( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		switch ( $post->post_type ) {
			case 'orgahb_building':
				self::save_building( $post_id );
				break;
			case 'orgahb_process':
				self::save_process( $post_id );
				break;
			case 'orgahb_document':
				self::save_document( $post_id );
				break;
			case 'orgahb_page':
				self::save_page( $post_id );
				break;
		}
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Private save methods
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * @param int $post_id
	 * @return void
	 */
	private static function save_building( int $post_id ): void {
		if ( ! isset( $_POST['_orgahb_building_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['_orgahb_building_nonce'] ) ),
			self::NONCE_BUILDING
		) ) {
			return;
		}
		if ( ! current_user_can( 'edit_orgahb_building', $post_id ) ) {
			return;
		}

		// Details.
		ORGAHB_Buildings::save_meta( $post_id, array(
			'code'      => wp_unslash( $_POST['orgahb_building_code'] ?? '' ),
			'address'   => wp_unslash( $_POST['orgahb_building_address'] ?? '' ),
			'contacts'  => wp_unslash( $_POST['orgahb_building_contacts'] ?? '' ),
			'emergency' => wp_unslash( $_POST['orgahb_emergency_notes'] ?? '' ),
			'active'    => isset( $_POST['orgahb_building_active'] ),
		) );

		// Areas.
		$raw_areas = isset( $_POST['orgahb_areas'] ) && is_array( $_POST['orgahb_areas'] )
			? $_POST['orgahb_areas']
			: array();

		$areas = array();
		foreach ( $raw_areas as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$areas[] = array(
				'key'         => sanitize_key( $row['key'] ?? '' ),
				'label'       => sanitize_text_field( wp_unslash( $row['label'] ?? '' ) ),
				'description' => sanitize_textarea_field( wp_unslash( $row['description'] ?? '' ) ),
				'sort_order'  => (int) ( $row['sort_order'] ?? 0 ),
			);
		}
		if ( ! empty( $areas ) ) {
			ORGAHB_Buildings::save_areas( $post_id, $areas );
		}

		// Shared meta (nonce already verified above).
		self::save_shared_meta( $post_id, false, true );
	}

	/**
	 * @param int $post_id
	 * @return void
	 */
	private static function save_process( int $post_id ): void {
		if ( ! isset( $_POST['_orgahb_process_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['_orgahb_process_nonce'] ) ),
			self::NONCE_PROCESS
		) ) {
			return;
		}
		if ( ! current_user_can( 'edit_orgahb_content', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_PROCESS_IMAGE_ID,
			absint( $_POST['orgahb_process_image_id'] ?? 0 ) );
		update_post_meta( $post_id, self::META_DIAGRAM_NOTATION,
			sanitize_text_field( wp_unslash( $_POST['orgahb_diagram_notation'] ?? '' ) ) );
		update_post_meta( $post_id, self::META_SOURCE_FORMAT,
			sanitize_text_field( wp_unslash( $_POST['orgahb_source_format'] ?? '' ) ) );
		update_post_meta( $post_id, self::META_SOURCE_ATTACHMENT_ID,
			absint( $_POST['orgahb_source_attachment_id'] ?? 0 ) );
		update_post_meta( $post_id, self::META_IS_FIELD_EXECUTABLE,
			isset( $_POST['orgahb_is_field_executable'] ) ? 1 : 0 );

		// Only persist hotspot JSON when it is valid JSON or intentionally empty.
		$raw_json = sanitize_textarea_field( wp_unslash( $_POST['orgahb_hotspots_json'] ?? '' ) );
		if ( '' !== $raw_json ) {
			json_decode( $raw_json );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				update_post_meta( $post_id, self::META_HOTSPOTS_JSON, $raw_json );
			}
		} else {
			update_post_meta( $post_id, self::META_HOTSPOTS_JSON, '' );
		}

		self::save_shared_meta( $post_id, false, false );
	}

	/**
	 * @param int $post_id
	 * @return void
	 */
	private static function save_document( int $post_id ): void {
		if ( ! isset( $_POST['_orgahb_document_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['_orgahb_document_nonce'] ) ),
			self::NONCE_DOCUMENT
		) ) {
			return;
		}
		if ( ! current_user_can( 'edit_orgahb_content', $post_id ) ) {
			return;
		}

		$att_id = absint( $_POST['orgahb_current_attachment_id'] ?? 0 );
		update_post_meta( $post_id, self::META_CURRENT_ATTACHMENT_ID, $att_id );
		update_post_meta( $post_id, self::META_DOCUMENT_DISPLAY_MODE,
			sanitize_text_field( wp_unslash( $_POST['orgahb_document_display_mode'] ?? 'pdf_inline' ) ) );

		// Sync MIME type and file size from the attached media item.
		if ( $att_id ) {
			$mime = get_post_mime_type( $att_id );
			$path = get_attached_file( $att_id );
			update_post_meta( $post_id, self::META_DOCUMENT_MIME, is_string( $mime ) ? $mime : '' );
			update_post_meta(
				$post_id,
				self::META_DOCUMENT_SIZE,
				( $path && file_exists( $path ) ) ? (int) filesize( $path ) : 0
			);
		}

		self::save_shared_meta( $post_id, true, false );
	}

	/**
	 * @param int $post_id
	 * @return void
	 */
	private static function save_page( int $post_id ): void {
		if ( ! isset( $_POST['_orgahb_page_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['_orgahb_page_nonce'] ) ),
			self::NONCE_PAGE
		) ) {
			return;
		}
		if ( ! current_user_can( 'edit_orgahb_content', $post_id ) ) {
			return;
		}

		self::save_shared_meta( $post_id, true, false );
	}

	/**
	 * Persists the fields rendered by render_content_meta().
	 *
	 * @param int  $post_id
	 * @param bool $with_requires_ack  True for page and document.
	 * @param bool $is_building        Buildings skip version/validity/change-log fields.
	 * @return void
	 */
	private static function save_shared_meta(
		int $post_id,
		bool $with_requires_ack,
		bool $is_building
	): void {
		// Ownership + review — applies to all CPTs.
		ORGAHB_Buildings::save_meta( $post_id, array(
			'owner_user_id'  => absint( $_POST['orgahb_owner_user_id'] ?? 0 ),
			'deputy_user_id' => absint( $_POST['orgahb_deputy_user_id'] ?? 0 ),
			'owner_label'    => sanitize_text_field( wp_unslash( $_POST['orgahb_owner_label'] ?? '' ) ),
			'next_review'    => sanitize_text_field( wp_unslash( $_POST['orgahb_next_review'] ?? '' ) ),
			'search_aliases' => sanitize_text_field( wp_unslash( $_POST['orgahb_search_aliases'] ?? '' ) ),
		) );

		// Version / validity / change-log — content CPTs only, not buildings.
		if ( ! $is_building ) {
			update_post_meta( $post_id, self::META_VERSION_LABEL,
				sanitize_text_field( wp_unslash( $_POST['orgahb_version_label'] ?? '' ) ) );
			update_post_meta( $post_id, self::META_VALID_FROM,
				sanitize_text_field( wp_unslash( $_POST['orgahb_valid_from'] ?? '' ) ) );
			update_post_meta( $post_id, self::META_VALID_UNTIL,
				sanitize_text_field( wp_unslash( $_POST['orgahb_valid_until'] ?? '' ) ) );
			update_post_meta( $post_id, self::META_CHANGE_LOG,
				sanitize_textarea_field( wp_unslash( $_POST['orgahb_change_log'] ?? '' ) ) );
		}

		if ( $with_requires_ack ) {
			update_post_meta( $post_id, self::META_REQUIRES_ACK,
				isset( $_POST['orgahb_requires_ack'] ) ? 1 : 0 );
		}
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Workflow notices
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Displays admin notices after a workflow transition redirect.
	 *
	 * @return void
	 */
	public static function workflow_admin_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['orgahb_wf_error'] ) ) {
			$msg = sanitize_text_field( wp_unslash( $_GET['orgahb_wf_error'] ) );
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html( $msg )
			);
		}

		if ( isset( $_GET['orgahb_wf_done'] ) ) {
			$done_labels = array(
				'submit'  => __( 'Content submitted for review.', 'orgahb-manager' ),
				'approve' => __( 'Content approved and published.', 'orgahb-manager' ),
				'return'  => __( 'Content returned for revision.', 'orgahb-manager' ),
				'archive' => __( 'Content archived.', 'orgahb-manager' ),
				'restore' => __( 'Content restored to draft.', 'orgahb-manager' ),
			);
			$action = sanitize_key( $_GET['orgahb_wf_done'] );
			$msg    = $done_labels[ $action ] ?? __( 'Workflow action completed.', 'orgahb-manager' );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $msg )
			);
		}
		// phpcs:enable
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Render: Workflow metabox (all CPTs)
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Renders the Workflow metabox sidebar panel.
	 *
	 * Shows current status and available transition buttons based on the
	 * current user's capabilities (spec §20.3).
	 *
	 * @param WP_Post $post
	 * @return void
	 */
	public static function render_workflow( WP_Post $post ): void {
		$status   = $post->post_status;
		$post_id  = $post->ID;
		$user_id  = get_current_user_id();

		$can_submit  = current_user_can( 'submit_orgahb_content' );
		$can_approve = current_user_can( 'approve_orgahb_content' );

		// Status label map.
		$labels = array(
			'draft'            => __( 'Draft', 'orgahb-manager' ),
			'auto-draft'       => __( 'Draft', 'orgahb-manager' ),
			'pending'          => __( 'Pending Review', 'orgahb-manager' ),
			'publish'          => __( 'Published', 'orgahb-manager' ),
			ORGAHB_Workflow::STATUS_ARCHIVED => __( 'Archived', 'orgahb-manager' ),
		);
		$label = $labels[ $status ] ?? esc_html( $status );

		$archived_reason = (string) get_post_meta( $post_id, self::META_ARCHIVED_REASON, true );
		?>
		<p class="orgahb-workflow-status">
			<?php esc_html_e( 'Status:', 'orgahb-manager' ); ?>
			<strong><?php echo esc_html( $label ); ?></strong>
		</p>
		<?php if ( $archived_reason ) : ?>
			<p class="orgahb-workflow-reason">
				<em><?php echo esc_html( $archived_reason ); ?></em>
			</p>
		<?php endif; ?>

		<?php /* Submit for review (draft → pending) */ ?>
		<?php if ( $can_submit && in_array( $status, array( 'draft', 'auto-draft' ), true ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'orgahb_workflow_' . $post_id, '_orgahb_wf_nonce' ); ?>
				<input type="hidden" name="action"   value="orgahb_workflow">
				<input type="hidden" name="post_id"  value="<?php echo esc_attr( $post_id ); ?>">
				<input type="hidden" name="wf_action" value="submit">
				<button type="submit" class="button orgahb-wf-btn">
					<?php esc_html_e( 'Submit for Review', 'orgahb-manager' ); ?>
				</button>
			</form>
		<?php endif; ?>

		<?php /* Approve (pending → publish) */ ?>
		<?php if ( $can_approve && 'pending' === $status ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'orgahb_workflow_' . $post_id, '_orgahb_wf_nonce' ); ?>
				<input type="hidden" name="action"    value="orgahb_workflow">
				<input type="hidden" name="post_id"   value="<?php echo esc_attr( $post_id ); ?>">
				<input type="hidden" name="wf_action" value="approve">
				<button type="submit" class="button button-primary orgahb-wf-btn">
					<?php esc_html_e( 'Approve', 'orgahb-manager' ); ?>
				</button>
			</form>
		<?php endif; ?>

		<?php /* Return for revision (pending → draft) */ ?>
		<?php if ( $can_approve && 'pending' === $status ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'orgahb_workflow_' . $post_id, '_orgahb_wf_nonce' ); ?>
				<input type="hidden" name="action"    value="orgahb_workflow">
				<input type="hidden" name="post_id"   value="<?php echo esc_attr( $post_id ); ?>">
				<input type="hidden" name="wf_action" value="return">
				<label for="orgahb-wf-comment-<?php echo esc_attr( $post_id ); ?>" class="orgahb-wf-label">
					<?php esc_html_e( 'Reviewer comment:', 'orgahb-manager' ); ?>
				</label>
				<textarea
					id="orgahb-wf-comment-<?php echo esc_attr( $post_id ); ?>"
					name="wf_comment"
					class="orgahb-wf-comment widefat"
					rows="2"
					placeholder="<?php esc_attr_e( 'Required: explain what needs changing', 'orgahb-manager' ); ?>"
				></textarea>
				<button type="submit" class="button orgahb-wf-btn">
					<?php esc_html_e( 'Return for Revision', 'orgahb-manager' ); ?>
				</button>
			</form>
		<?php endif; ?>

		<?php /* Archive (any → archived) */ ?>
		<?php if ( $can_approve && ORGAHB_Workflow::STATUS_ARCHIVED !== $status ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'orgahb_workflow_' . $post_id, '_orgahb_wf_nonce' ); ?>
				<input type="hidden" name="action"    value="orgahb_workflow">
				<input type="hidden" name="post_id"   value="<?php echo esc_attr( $post_id ); ?>">
				<input type="hidden" name="wf_action" value="archive">
				<label for="orgahb-wf-reason-<?php echo esc_attr( $post_id ); ?>" class="orgahb-wf-label">
					<?php esc_html_e( 'Archive reason (optional):', 'orgahb-manager' ); ?>
				</label>
				<input
					type="text"
					id="orgahb-wf-reason-<?php echo esc_attr( $post_id ); ?>"
					name="wf_comment"
					class="widefat orgahb-wf-reason"
					placeholder="<?php esc_attr_e( 'e.g. superseded by v2', 'orgahb-manager' ); ?>"
				>
				<button type="submit" class="button orgahb-wf-btn orgahb-wf-archive-btn">
					<?php esc_html_e( 'Archive', 'orgahb-manager' ); ?>
				</button>
			</form>
		<?php endif; ?>

		<?php /* Restore (archived → draft) */ ?>
		<?php if ( $can_approve && ORGAHB_Workflow::STATUS_ARCHIVED === $status ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'orgahb_workflow_' . $post_id, '_orgahb_wf_nonce' ); ?>
				<input type="hidden" name="action"    value="orgahb_workflow">
				<input type="hidden" name="post_id"   value="<?php echo esc_attr( $post_id ); ?>">
				<input type="hidden" name="wf_action" value="restore">
				<button type="submit" class="button orgahb-wf-btn">
					<?php esc_html_e( 'Restore to Draft', 'orgahb-manager' ); ?>
				</button>
			</form>
		<?php endif; ?>
		<?php
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Handler: workflow admin_post
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Handles all workflow transition form submissions.
	 *
	 * POST action: orgahb_workflow
	 * Required fields: post_id, wf_action, _orgahb_wf_nonce
	 * Optional: wf_comment (reviewer comment / archive reason)
	 *
	 * @return void
	 */
	public static function handle_workflow_action(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Unauthorized.', 'orgahb-manager' ), 403 );
		}

		$post_id   = absint( $_POST['post_id'] ?? 0 );
		$wf_action = sanitize_key( $_POST['wf_action'] ?? '' );
		$comment   = sanitize_textarea_field( wp_unslash( $_POST['wf_comment'] ?? '' ) );

		if ( ! $post_id || ! $wf_action ) {
			wp_die( esc_html__( 'Invalid request.', 'orgahb-manager' ), 400 );
		}

		if ( ! check_admin_referer( 'orgahb_workflow_' . $post_id, '_orgahb_wf_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'orgahb-manager' ), 403 );
		}

		$user_id = get_current_user_id();

		$result = match ( $wf_action ) {
			'submit'  => ORGAHB_Workflow::submit_for_review( $post_id, $user_id ),
			'approve' => ORGAHB_Workflow::approve( $post_id, $user_id, $comment ?: null ),
			'return'  => ORGAHB_Workflow::return_for_revision( $post_id, $user_id, $comment ),
			'archive' => ORGAHB_Workflow::archive( $post_id, $user_id, $comment ),
			'restore' => ORGAHB_Workflow::restore( $post_id, $user_id ),
			default   => new \WP_Error( 'orgahb_unknown_action', __( 'Unknown workflow action.', 'orgahb-manager' ) ),
		};

		$redirect = get_edit_post_link( $post_id, 'url' )
			?: admin_url( 'edit.php?post_type=' . get_post_type( $post_id ) );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'orgahb_wf_error', rawurlencode( $result->get_error_message() ), $redirect );
		} else {
			$redirect = add_query_arg( 'orgahb_wf_done', $wf_action, $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
