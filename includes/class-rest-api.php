<?php
/**
 * REST API endpoint registration and handlers.
 *
 * Namespace : orgahb/v1
 *
 * Field-facing endpoints (require read_orgahb_content or higher):
 *   GET  /buildings/by-token/{qr_token}
 *   GET  /buildings/{id}/bundle
 *   POST /acknowledgments
 *   POST /processes/{id}/execute
 *   GET  /processes/{id}/hotspots/{hotspot_id}/executions
 *   GET  /buildings/{id}/observations
 *   POST /buildings/{id}/observations
 *   POST /observations/{id}/resolve
 *
 * Admin endpoints (require manage_orgahb_buildings):
 *   GET    /buildings/{id}/links
 *   POST   /buildings/{id}/links
 *   PATCH  /links/{id}
 *   DELETE /links/{id}
 *   PUT    /buildings/{id}/areas
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * REST API route registration and request handling.
 */
final class ORGAHB_REST_API {

	const NAMESPACE = 'orgahb/v1';

	/** Transient prefix for per-building structural bundle (no user-specific fields). */
	const BUNDLE_TRANSIENT_PREFIX = 'orgahb_bundle_';

	/** Bundle cache lifetime in seconds (1 hour). */
	const BUNDLE_CACHE_TTL = HOUR_IN_SECONDS;

	// ── Hook registration ─────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );

		// Bundle cache invalidation hooks run on every request type so that
		// admin-side changes bust the cache before the next field request.
		add_action( 'save_post',                     array( self::class, 'on_post_change' ), 20, 1 );
		add_action( 'orgahb_building_links_changed', array( self::class, 'invalidate_bundle_cache' ), 10, 1 );
	}

	// ── Route registration ────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function register_routes(): void {
		$ns = self::NAMESPACE;

		// ── Building: by QR token ─────────────────────────────────────────────
		register_rest_route( $ns, '/buildings/by-token/(?P<token>[a-zA-Z0-9\-]{36})', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'get_building_by_token' ),
			'permission_callback' => array( self::class, 'can_read_content' ),
			'args'                => array(
				'token' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// ── Building: content bundle ──────────────────────────────────────────
		register_rest_route( $ns, '/buildings/(?P<id>\d+)/bundle', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'get_building_bundle' ),
			'permission_callback' => array( self::class, 'can_read_content' ),
			'args'                => array(
				'id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );

		// ── Acknowledgments ───────────────────────────────────────────────────
		register_rest_route( $ns, '/acknowledgments', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'post_acknowledgment' ),
			'permission_callback' => array( self::class, 'can_acknowledge' ),
			'args'                => array(
				'post_id'       => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'revision_id'   => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'version_label' => array( 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'source'        => array( 'required' => false, 'default' => 'api', 'sanitize_callback' => 'sanitize_text_field' ),
				'queue_uuid'    => array( 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// ── Process: log execution ────────────────────────────────────────────
		register_rest_route( $ns, '/processes/(?P<id>\d+)/execute', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'post_execution' ),
			'permission_callback' => array( self::class, 'can_log_step' ),
			'args'                => array(
				'id'                 => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'building_id'        => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'hotspot_id'         => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'outcome'            => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'post_revision_id'   => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'area_key'           => array( 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_key' ),
				'note'               => array( 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ),
				'client_recorded_at' => array( 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'queue_uuid'         => array( 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// ── Process: hotspot execution history ────────────────────────────────
		register_rest_route( $ns, '/processes/(?P<id>\d+)/hotspots/(?P<hotspot_id>[^/]+)/executions', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( self::class, 'get_hotspot_executions' ),
			'permission_callback' => array( self::class, 'can_read_content' ),
			'args'                => array(
				'id'          => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'hotspot_id'  => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'building_id' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
				'area_key'    => array( 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_key' ),
				'limit'       => array( 'required' => false, 'default' => 20, 'sanitize_callback' => 'absint' ),
			),
		) );

		// ── Observations: list + create ───────────────────────────────────────
		register_rest_route( $ns, '/buildings/(?P<id>\d+)/observations', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_observations' ),
				'permission_callback' => array( self::class, 'can_read_content' ),
				'args'                => array(
					'id'       => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'status'   => array( 'required' => false, 'default' => 'open_only', 'sanitize_callback' => 'sanitize_text_field' ),
					'area_key' => array( 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_key' ),
					'limit'    => array( 'required' => false, 'default' => 50, 'sanitize_callback' => 'absint' ),
					'offset'   => array( 'required' => false, 'default' => 0, 'sanitize_callback' => 'absint' ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'post_observation' ),
				'permission_callback' => array( self::class, 'can_create_observation' ),
				'args'                => array(
					'id'                 => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'summary'            => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'category'           => array( 'required' => false, 'default' => 'general', 'sanitize_callback' => 'sanitize_text_field' ),
					'area_key'           => array( 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_key' ),
					'details'            => array( 'required' => false, 'default' => '', 'sanitize_callback' => 'wp_kses_post' ),
					'external_reference' => array( 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'queue_uuid'         => array( 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
		) );

		// ── Observations: resolve ─────────────────────────────────────────────
		register_rest_route( $ns, '/observations/(?P<id>\d+)/resolve', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( self::class, 'post_resolve_observation' ),
			'permission_callback' => array( self::class, 'can_create_observation' ),
			'args'                => array(
				'id'     => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'status' => array( 'required' => false, 'default' => 'resolved', 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// ── Admin: building links ─────────────────────────────────────────────
		register_rest_route( $ns, '/buildings/(?P<id>\d+)/links', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_building_links' ),
				'permission_callback' => array( self::class, 'can_manage_buildings' ),
				'args'                => array(
					'id'       => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'area_key' => array( 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_key' ),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'post_building_link' ),
				'permission_callback' => array( self::class, 'can_manage_buildings' ),
				'args'                => array(
					'id'                      => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'area_key'                => array( 'required' => false, 'default' => 'general', 'sanitize_callback' => 'sanitize_key' ),
					'content_type'            => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'content_id'              => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'sort_order'              => array( 'required' => false, 'default' => 0, 'sanitize_callback' => 'absint' ),
					'is_featured'             => array( 'required' => false, 'default' => false ),
					'local_note'              => array( 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
					'advisory_interval_label' => array( 'required' => false, 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
		) );

		register_rest_route( $ns, '/links/(?P<id>\d+)', array(
			array(
				'methods'             => 'PATCH',
				'callback'            => array( self::class, 'patch_building_link' ),
				'permission_callback' => array( self::class, 'can_manage_buildings' ),
				'args'                => array(
					'id'                      => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'sort_order'              => array( 'required' => false, 'sanitize_callback' => 'absint' ),
					'is_featured'             => array( 'required' => false ),
					'local_note'              => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'advisory_interval_label' => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( self::class, 'delete_building_link' ),
				'permission_callback' => array( self::class, 'can_manage_buildings' ),
				'args'                => array(
					'id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			),
		) );

		// ── Admin: building areas ─────────────────────────────────────────────
		register_rest_route( $ns, '/buildings/(?P<id>\d+)/areas', array(
			'methods'             => 'PUT',
			'callback'            => array( self::class, 'put_building_areas' ),
			'permission_callback' => array( self::class, 'can_manage_buildings' ),
			'args'                => array(
				'id'    => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'areas' => array( 'required' => true ),
			),
		) );

		// GET /items/{id}/backlinks — processes whose hotspots link to this item.
		register_rest_route( $ns, '/items/(?P<id>\d+)/backlinks', array(
			'methods'             => 'GET',
			'callback'            => array( self::class, 'get_item_backlinks' ),
			'permission_callback' => array( self::class, 'can_read_content' ),
			'args'                => array(
				'id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
			),
		) );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Permission callbacks
	// ────────────────────────────────────────────────────────────────────────────

	/** @return true|WP_Error */
	public static function can_read_content(): true|WP_Error {
		return current_user_can( 'read_orgahb_content' ) ? true : self::forbidden();
	}

	/** @return true|WP_Error */
	public static function can_acknowledge(): true|WP_Error {
		return current_user_can( 'acknowledge_orgahb_content' ) ? true : self::forbidden();
	}

	/** @return true|WP_Error */
	public static function can_log_step(): true|WP_Error {
		return current_user_can( 'log_orgahb_process_step' ) ? true : self::forbidden();
	}

	/** @return true|WP_Error */
	public static function can_create_observation(): true|WP_Error {
		return current_user_can( 'create_orgahb_observation' ) ? true : self::forbidden();
	}

	/** @return true|WP_Error */
	public static function can_manage_buildings(): true|WP_Error {
		return current_user_can( 'manage_orgahb_buildings' ) ? true : self::forbidden();
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Handlers: building
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * GET /buildings/by-token/{token}
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_building_by_token( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$token       = $request->get_param( 'token' );
		$building_id = ORGAHB_QR::find_building( $token );

		if ( ! $building_id ) {
			return self::not_found( __( 'No building found for this QR token.', 'orgahb-manager' ) );
		}

		$post = get_post( $building_id );
		if ( ! $post || 'orgahb_building' !== $post->post_type ) {
			return self::not_found( __( 'Building not found.', 'orgahb-manager' ) );
		}

		return rest_ensure_response( self::format_building( $post ) );
	}

	/**
	 * GET /buildings/{id}/bundle
	 *
	 * Returns all published content linked to the building, grouped by area.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_building_bundle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$building_id = (int) $request->get_param( 'id' );
		$post        = get_post( $building_id );

		if ( ! $post || 'orgahb_building' !== $post->post_type ) {
			return self::not_found( __( 'Building not found.', 'orgahb-manager' ) );
		}

		// ── Try structural cache ──────────────────────────────────────────────
		// The structural bundle (all fields except user_has_acked) is cached per
		// building. The per-user ack overlay is applied below (spec §35.2).
		$cache_key = self::BUNDLE_TRANSIENT_PREFIX . $building_id;
		$cached    = get_transient( $cache_key );

		if ( false === $cached ) {
			$areas = ORGAHB_Buildings::get_areas( $building_id );
			$links = ORGAHB_Building_Links::get_for_building( $building_id );

			// Index links by area_key for fast lookup.
			$by_area = array();
			foreach ( $links as $link ) {
				$by_area[ $link['area_key'] ][] = $link;
			}

			$result_areas = array();
			foreach ( $areas as $area ) {
				$area_key   = $area['key'];
				$area_links = $by_area[ $area_key ] ?? array();

				$items = array();
				$today = current_time( 'Y-m-d' );
				foreach ( $area_links as $link ) {
					$item_post = get_post( (int) $link['content_id'] );
					if ( ! $item_post || 'publish' !== $item_post->post_status ) {
						continue;
					}
					// Exclude items with a future valid_from (spec §21.4).
					$valid_from = (string) get_post_meta( (int) $link['content_id'], ORGAHB_Metaboxes::META_VALID_FROM, true );
					if ( '' !== $valid_from && $valid_from > $today ) {
						continue;
					}
					// Exclude items with an expired valid_until.
					$valid_until = (string) get_post_meta( (int) $link['content_id'], ORGAHB_Metaboxes::META_VALID_UNTIL, true );
					if ( '' !== $valid_until && $valid_until < $today ) {
						continue;
					}
					$items[] = self::format_bundle_item( $link, $item_post );
				}

				$result_areas[] = array(
					'key'         => $area_key,
					'label'       => $area['label'],
					'description' => $area['description'] ?? '',
					'sort_order'  => (int) ( $area['sort_order'] ?? 0 ),
					'items'       => $items,
				);
			}

			$cached = array(
				'building' => self::format_building( $post ),
				'areas'    => $result_areas,
			);

			set_transient( $cache_key, $cached, self::BUNDLE_CACHE_TTL );
		}

		// ── Per-user ack overlay ──────────────────────────────────────────────
		// user_has_acked is user-specific and cannot be shared in the structural
		// cache. Overlay it now with a single query per item that requires ack.
		$user_id = get_current_user_id();
		$areas   = $cached['areas'];
		foreach ( $areas as &$area ) {
			foreach ( $area['items'] as &$item ) {
				if ( isset( $item['meta']['requires_ack'] ) ) {
					$item['meta']['user_has_acked'] = ORGAHB_Acknowledgments::has_acknowledged(
						$item['content_id'],
						$user_id,
						$item['meta']['current_revision_id']
					);
				}
			}
			unset( $item );
		}
		unset( $area );

		return rest_ensure_response( array(
			'building_id' => $building_id,
			'building'    => $cached['building'],
			'areas'       => $areas,
		) );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Handlers: acknowledgments
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * POST /acknowledgments
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_acknowledgment( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id     = (int) $request->get_param( 'post_id' );
		$revision_id = (int) $request->get_param( 'revision_id' );
		$version     = (string) $request->get_param( 'version_label' );
		$source      = (string) $request->get_param( 'source' );
		$queue_uuid  = (string) $request->get_param( 'queue_uuid' );

		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, ORGAHB_Acknowledgments::APPLICABLE_TYPES, true ) ) {
			return self::bad_request( __( 'Invalid post. Only handbook pages and documents can be acknowledged.', 'orgahb-manager' ) );
		}
		if ( 'publish' !== $post->post_status ) {
			return self::bad_request( __( 'Only published content can be acknowledged.', 'orgahb-manager' ) );
		}

		// Offline deduplication — return success without inserting.
		if ( '' !== $queue_uuid && ORGAHB_Acknowledgments::uuid_exists( $queue_uuid ) ) {
			return rest_ensure_response( array( 'deduplicated' => true ) );
		}

		$source = in_array( $source, array( 'ui', 'api' ), true ) ? $source : 'api';
		$row_id = ORGAHB_Acknowledgments::record( $post_id, get_current_user_id(), $revision_id, $version, $source, $queue_uuid );

		if ( ! $row_id ) {
			return self::server_error( __( 'Failed to record acknowledgment.', 'orgahb-manager' ) );
		}

		return rest_ensure_response( array( 'id' => $row_id ) );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Handlers: executions
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * POST /processes/{id}/execute
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_execution( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$process_id  = (int) $request->get_param( 'id' );
		$building_id = (int) $request->get_param( 'building_id' );
		$hotspot_id  = (string) $request->get_param( 'hotspot_id' );
		$outcome     = (string) $request->get_param( 'outcome' );
		$revision_id = (int) $request->get_param( 'post_revision_id' );
		$area_key    = (string) $request->get_param( 'area_key' );
		$note        = (string) $request->get_param( 'note' );
		$client_at   = (string) $request->get_param( 'client_recorded_at' );
		$queue_uuid  = (string) $request->get_param( 'queue_uuid' );

		// Validate outcome.
		if ( ! in_array( $outcome, ORGAHB_Executions::OUTCOMES, true ) ) {
			return self::bad_request( sprintf(
				/* translators: %s: comma-separated valid outcomes */
				__( 'Invalid outcome. Allowed values: %s.', 'orgahb-manager' ),
				implode( ', ', ORGAHB_Executions::OUTCOMES )
			) );
		}

		// Offline deduplication — return success without inserting.
		if ( '' !== $queue_uuid && ORGAHB_Executions::uuid_exists( $queue_uuid ) ) {
			return rest_ensure_response( array( 'deduplicated' => true ) );
		}

		// Process must exist and be published.
		$process = get_post( $process_id );
		if ( ! $process || 'orgahb_process' !== $process->post_type || 'publish' !== $process->post_status ) {
			return self::bad_request( __( 'Process not found or not published.', 'orgahb-manager' ) );
		}

		// Building must exist and be active.
		$building = get_post( $building_id );
		if ( ! $building || 'orgahb_building' !== $building->post_type ) {
			return self::bad_request( __( 'Building not found.', 'orgahb-manager' ) );
		}
		if ( ! ORGAHB_Buildings::is_active( $building_id ) ) {
			return self::bad_request( __( 'This building is not active.', 'orgahb-manager' ) );
		}

		// Validate hotspot kind (must be 'step', not 'link').
		$hotspot_error = self::validate_executable_hotspot( $process_id, $hotspot_id );
		if ( is_wp_error( $hotspot_error ) ) {
			return $hotspot_error;
		}

		// Process must be linked to this building in the bundle.
		if ( ! self::process_is_linked_to_building( $process_id, $building_id ) ) {
			return self::bad_request( __( 'This process is not linked to the specified building.', 'orgahb-manager' ) );
		}

		// Resolve client-provided timestamp for executed_at (spec §30.4).
		$executed_at = '';
		if ( '' !== $client_at ) {
			try {
				$dt          = new DateTimeImmutable( $client_at, new DateTimeZone( 'UTC' ) );
				$executed_at = $dt->format( 'Y-m-d H:i:s.u' );
			} catch ( Exception $e ) {
				// Invalid timestamp — let ORGAHB_Executions use server time.
			}
		}

		$data = array(
			'post_id'            => $process_id,
			'hotspot_id'         => $hotspot_id,
			'building_id'        => $building_id,
			'outcome'            => $outcome,
			'post_revision_id'   => $revision_id,
			'user_id'            => get_current_user_id(),
			'source'             => 'api',
			'post_version_label' => (string) get_post_meta( $process_id, ORGAHB_Metaboxes::META_VERSION_LABEL, true ),
		);

		if ( '' !== $area_key )    { $data['area_key']    = $area_key; }
		if ( '' !== $note )        { $data['note']        = $note; }
		if ( '' !== $executed_at ) { $data['executed_at'] = $executed_at; }
		if ( '' !== $queue_uuid )  { $data['queue_uuid']  = $queue_uuid; }

		$row_id = ORGAHB_Executions::record( $data );

		if ( ! $row_id ) {
			return self::server_error( __( 'Failed to record execution.', 'orgahb-manager' ) );
		}

		return rest_ensure_response( array( 'id' => $row_id ) );
	}

	/**
	 * GET /processes/{id}/hotspots/{hotspot_id}/executions
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_hotspot_executions( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$process_id  = (int) $request->get_param( 'id' );
		$hotspot_id  = (string) $request->get_param( 'hotspot_id' );
		$limit       = min( (int) $request->get_param( 'limit' ), 100 );
		$building_id = $request->get_param( 'building_id' );

		if ( $building_id ) {
			// Scoped to a specific building: fetch by building then filter in PHP.
			$rows = ORGAHB_Executions::get_for_building( (int) $building_id, array( 'limit' => $limit ) );
			$rows = array_values( array_filter( $rows, static function ( array $row ) use ( $process_id, $hotspot_id ): bool {
				return (int) $row['post_id'] === $process_id && $row['hotspot_id'] === $hotspot_id;
			} ) );
		} else {
			$rows = ORGAHB_Executions::get_for_process( $process_id, array(
				'hotspot_id' => $hotspot_id,
				'limit'      => $limit,
			) );
		}

		return rest_ensure_response( array_values( $rows ) );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Handlers: observations
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * GET /buildings/{id}/observations
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_observations( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$building_id = (int) $request->get_param( 'id' );

		$post = get_post( $building_id );
		if ( ! $post || 'orgahb_building' !== $post->post_type ) {
			return self::not_found( __( 'Building not found.', 'orgahb-manager' ) );
		}

		$area_key = (string) $request->get_param( 'area_key' );
		$args     = array(
			'status' => (string) $request->get_param( 'status' ),
			'limit'  => min( (int) $request->get_param( 'limit' ), 200 ),
			'offset' => (int) $request->get_param( 'offset' ),
		);
		if ( '' !== $area_key ) {
			$args['area_key'] = $area_key;
		}

		return rest_ensure_response( array_values(
			ORGAHB_Observations::get_for_building( $building_id, $args )
		) );
	}

	/**
	 * POST /buildings/{id}/observations
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_observation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$building_id = (int) $request->get_param( 'id' );

		$post = get_post( $building_id );
		if ( ! $post || 'orgahb_building' !== $post->post_type ) {
			return self::not_found( __( 'Building not found.', 'orgahb-manager' ) );
		}
		if ( ! ORGAHB_Buildings::is_active( $building_id ) ) {
			return self::bad_request( __( 'This building is not active.', 'orgahb-manager' ) );
		}

		$summary  = trim( (string) $request->get_param( 'summary' ) );
		if ( '' === $summary ) {
			return self::bad_request( __( 'summary is required.', 'orgahb-manager' ) );
		}

		$area_key   = (string) $request->get_param( 'area_key' );
		$details    = (string) $request->get_param( 'details' );
		$ext_ref    = (string) $request->get_param( 'external_reference' );
		$queue_uuid = (string) $request->get_param( 'queue_uuid' );

		// Offline deduplication — return success without inserting.
		if ( '' !== $queue_uuid && ORGAHB_Observations::uuid_exists( $queue_uuid ) ) {
			return rest_ensure_response( array( 'deduplicated' => true ) );
		}

		$data = array(
			'building_id'    => $building_id,
			'summary'        => $summary,
			'category'       => (string) $request->get_param( 'category' ) ?: 'general',
			'author_user_id' => get_current_user_id(),
			'source'         => 'api',
		);

		if ( '' !== $area_key )   { $data['area_key']           = $area_key; }
		if ( '' !== $details )    { $data['details']            = $details; }
		if ( '' !== $ext_ref )    { $data['external_reference'] = $ext_ref; }
		if ( '' !== $queue_uuid ) { $data['queue_uuid']         = $queue_uuid; }

		$row_id = ORGAHB_Observations::create( $data );
		if ( ! $row_id ) {
			return self::server_error( __( 'Failed to create observation.', 'orgahb-manager' ) );
		}

		/**
		 * Fires after a new building observation is created via the REST API.
		 *
		 * @param int   $row_id      Inserted observation ID.
		 * @param int   $building_id Building post ID.
		 * @param array $data        Data array passed to ORGAHB_Observations::create().
		 */
		do_action( 'orgahb_observation_created', $row_id, $building_id, $data );

		return rest_ensure_response( array( 'id' => $row_id ) );
	}

	/**
	 * POST /observations/{id}/resolve
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_resolve_observation( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$obs_id = (int) $request->get_param( 'id' );
		$status = (string) $request->get_param( 'status' );

		if ( ! in_array( $status, ORGAHB_Observations::STATUSES, true ) ) {
			$status = 'resolved';
		}

		$ok = ORGAHB_Observations::update_status( $obs_id, $status, get_current_user_id() );
		if ( ! $ok ) {
			return self::not_found( __( 'Observation not found or status update failed.', 'orgahb-manager' ) );
		}

		return rest_ensure_response( array( 'success' => true, 'status' => $status ) );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Handlers: admin — building links
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * GET /buildings/{id}/links
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_building_links( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$building_id = (int) $request->get_param( 'id' );
		$area_key    = (string) $request->get_param( 'area_key' );

		$rows = ORGAHB_Building_Links::get_for_building(
			$building_id,
			'' !== $area_key ? $area_key : null
		);

		return rest_ensure_response( array_values( $rows ) );
	}

	/**
	 * POST /buildings/{id}/links
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_building_link( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$building_id  = (int) $request->get_param( 'id' );
		$content_type = (string) $request->get_param( 'content_type' );
		$content_id   = (int) $request->get_param( 'content_id' );
		$area_key     = sanitize_key( (string) $request->get_param( 'area_key' ) ) ?: 'general';

		if ( ! in_array( $content_type, ORGAHB_Building_Links::CONTENT_TYPES, true ) ) {
			return self::bad_request( sprintf(
				/* translators: %s: comma-separated valid content types */
				__( 'Invalid content_type. Allowed: %s.', 'orgahb-manager' ),
				implode( ', ', ORGAHB_Building_Links::CONTENT_TYPES )
			) );
		}

		$expected_cpt = ORGAHB_Building_Links::CPT_MAP[ $content_type ];
		$item_post    = get_post( $content_id );
		if ( ! $item_post || $item_post->post_type !== $expected_cpt ) {
			return self::bad_request( __( 'Content item not found or wrong type.', 'orgahb-manager' ) );
		}

		$link_id = ORGAHB_Building_Links::add(
			$building_id,
			$area_key,
			$content_type,
			$content_id,
			array(
				'sort_order'              => (int) $request->get_param( 'sort_order' ),
				'is_featured'             => (bool) $request->get_param( 'is_featured' ),
				'local_note'              => (string) $request->get_param( 'local_note' ),
				'advisory_interval_label' => (string) $request->get_param( 'advisory_interval_label' ),
			)
		);

		if ( ! $link_id ) {
			return self::server_error( __( 'Failed to add building link.', 'orgahb-manager' ) );
		}

		return rest_ensure_response( array( 'id' => $link_id ) );
	}

	/**
	 * PATCH /links/{id}
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function patch_building_link( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$link_id = (int) $request->get_param( 'id' );

		$data = array();
		foreach ( array( 'sort_order', 'is_featured', 'local_note', 'advisory_interval_label' ) as $key ) {
			$val = $request->get_param( $key );
			if ( null !== $val ) {
				$data[ $key ] = $val;
			}
		}

		if ( ! ORGAHB_Building_Links::update( $link_id, $data ) ) {
			return self::not_found( __( 'Building link not found.', 'orgahb-manager' ) );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * DELETE /links/{id}
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_building_link( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$link_id = (int) $request->get_param( 'id' );

		if ( ! ORGAHB_Building_Links::remove( $link_id ) ) {
			return self::not_found( __( 'Building link not found.', 'orgahb-manager' ) );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Handlers: admin — areas
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * PUT /buildings/{id}/areas
	 *
	 * Replaces area definitions for a building, enforcing the 'general' area.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function put_building_areas( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$building_id = (int) $request->get_param( 'id' );
		$raw_areas   = $request->get_param( 'areas' );

		if ( ! is_array( $raw_areas ) ) {
			return self::bad_request( __( '"areas" must be an array.', 'orgahb-manager' ) );
		}

		$areas = array();
		foreach ( $raw_areas as $i => $raw ) {
			if ( ! is_array( $raw ) || empty( $raw['key'] ) ) {
				continue;
			}
			$areas[] = array(
				'key'         => sanitize_key( $raw['key'] ),
				'label'       => sanitize_text_field( $raw['label'] ?? '' ),
				'description' => sanitize_textarea_field( $raw['description'] ?? '' ),
				'sort_order'  => isset( $raw['sort_order'] ) ? (int) $raw['sort_order'] : $i,
			);
		}

		ORGAHB_Buildings::save_areas( $building_id, $areas );

		return rest_ensure_response( array(
			'areas' => ORGAHB_Buildings::get_areas( $building_id ),
		) );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Private helpers
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Serialises a building post + meta for API responses.
	 *
	 * @param WP_Post $post
	 * @return array<string, mixed>
	 */
	private static function format_building( WP_Post $post ): array {
		$id = $post->ID;

		// Cover image: use WordPress featured image (post thumbnail).
		$thumb_id  = (int) get_post_thumbnail_id( $id );
		$cover_url = $thumb_id
			? (string) wp_get_attachment_image_url( $thumb_id, 'large' )
			: '';

		return array(
			'id'     => $id,
			'title'  => $post->post_title,
			'slug'   => $post->post_name,
			'status' => $post->post_status,
			'meta'   => array(
				'code'            => ORGAHB_Buildings::get_code( $id ),
				'address'         => ORGAHB_Buildings::get_address( $id ),
				'contacts'        => ORGAHB_Buildings::get_contacts( $id ),
				'emergency_notes' => ORGAHB_Buildings::get_emergency_notes( $id ),
				'active'          => ORGAHB_Buildings::is_active( $id ),
				'qr_token'        => ORGAHB_QR::get_token( $id ),
				'next_review'     => ORGAHB_Buildings::get_next_review( $id ),
				'cover_url'       => $cover_url,
			),
			'areas'  => ORGAHB_Buildings::get_areas( $id ),
		);
	}

	/**
	 * Serialises a building-link row + content post for bundle responses.
	 *
	 * @param array<string, mixed> $link
	 * @param WP_Post              $post
	 * @return array<string, mixed>
	 */
	private static function format_bundle_item( array $link, WP_Post $post ): array {
		$id   = $post->ID;
		$type = $link['content_type'];

		// Get the most-recent published revision ID for acknowledgment / evidence
		// attribution (spec §19.1).  wp_get_post_revisions() returns newest-first;
		// we fall back to the post ID itself if no revisions exist.
		$revisions       = wp_get_post_revisions( $id, array( 'posts_per_page' => 1, 'fields' => 'ids' ) );
		$revision_id     = ! empty( $revisions ) ? (int) reset( $revisions ) : $id;

		$meta = array(
			'version_label'       => (string) get_post_meta( $id, ORGAHB_Metaboxes::META_VERSION_LABEL, true ),
			'change_log'          => (string) get_post_meta( $id, ORGAHB_Metaboxes::META_CHANGE_LOG, true ),
			'valid_from'          => (string) get_post_meta( $id, ORGAHB_Metaboxes::META_VALID_FROM, true ),
			'valid_until'         => (string) get_post_meta( $id, ORGAHB_Metaboxes::META_VALID_UNTIL, true ),
			'next_review'         => (string) get_post_meta( $id, ORGAHB_Buildings::META_NEXT_REVIEW, true ),
			'owner_name'          => (string) get_the_author_meta( 'display_name', $post->post_author ),
			'current_revision_id' => $revision_id,
		);

		if ( 'page' === $type || 'document' === $type ) {
			// user_has_acked is not included here — it's added as a per-user overlay
			// in get_building_bundle() after the structural cache is populated.
			$meta['requires_ack'] = (bool) get_post_meta( $id, ORGAHB_Metaboxes::META_REQUIRES_ACK, true );
		}

		if ( 'document' === $type ) {
			$att_id = (int) get_post_meta( $id, ORGAHB_Metaboxes::META_CURRENT_ATTACHMENT_ID, true );
			$meta['display_mode']   = (string) get_post_meta( $id, ORGAHB_Metaboxes::META_DOCUMENT_DISPLAY_MODE, true ) ?: 'pdf_inline';
			$meta['document_mime']  = (string) get_post_meta( $id, ORGAHB_Metaboxes::META_DOCUMENT_MIME, true );
			$meta['attachment_url'] = $att_id ? (string) wp_get_attachment_url( $att_id ) : '';
		}

		if ( 'process' === $type ) {
			$image_id = (int) get_post_meta( $id, ORGAHB_Metaboxes::META_PROCESS_IMAGE_ID, true );
			$meta['is_field_executable'] = (bool) get_post_meta( $id, ORGAHB_Metaboxes::META_IS_FIELD_EXECUTABLE, true );
			$meta['image_url']           = $image_id ? (string) wp_get_attachment_url( $image_id ) : '';
			$meta['hotspots_json']       = (string) get_post_meta( $id, ORGAHB_Metaboxes::META_HOTSPOTS_JSON, true );

			// Inline sanitized SVG for SVG diagrams (spec §28.1 — sanitized SVG preferred).
			// Delivered inline so Panzoom transforms the vector along with hotspot overlays.
			if ( $image_id ) {
				$mime = (string) get_post_mime_type( $image_id );
				if ( 'image/svg+xml' === $mime ) {
					$meta['image_svg_inline'] = class_exists( 'ORGAHB_SVG' )
						? ORGAHB_SVG::get_safe_inline( $image_id )
						: '';
				}
			}
		}

		return array(
			'link_id'                 => (int) $link['id'],
			'content_type'            => $type,
			'content_id'              => $id,
			'title'                   => $post->post_title,
			'status'                  => $post->post_status,
			'sort_order'              => (int) $link['sort_order'],
			'is_featured'             => (bool) $link['is_featured'],
			'local_note'              => (string) ( $link['local_note'] ?? '' ),
			'advisory_interval_label' => (string) ( $link['advisory_interval_label'] ?? '' ),
			'meta'                    => $meta,
		);
	}

	/**
	 * Validates that hotspot_id refers to a `kind=step` hotspot.
	 *
	 * Returns null on success (or when JSON is absent — graceful early adoption).
	 * Returns WP_Error only when the hotspot exists and is `kind=link`.
	 *
	 * @param int    $process_id
	 * @param string $hotspot_id
	 * @return WP_Error|null
	 */
	private static function validate_executable_hotspot( int $process_id, string $hotspot_id ): ?WP_Error {
		$json = (string) get_post_meta( $process_id, ORGAHB_Metaboxes::META_HOTSPOTS_JSON, true );
		if ( '' === $json ) {
			return null;
		}

		$hotspots = json_decode( $json, true );
		if ( ! is_array( $hotspots ) ) {
			return null;
		}

		foreach ( $hotspots as $hotspot ) {
			if ( ( $hotspot['id'] ?? '' ) !== $hotspot_id ) {
				continue;
			}
			if ( ( $hotspot['kind'] ?? 'step' ) !== 'step' ) {
				return self::bad_request( __( 'This hotspot is a navigation link and cannot log evidence.', 'orgahb-manager' ) );
			}
			return null;
		}

		return null; // Hotspot ID not in JSON yet — allow execution.
	}

	/**
	 * Checks whether a process post is linked to a given building.
	 *
	 * @param int $process_id
	 * @param int $building_id
	 * @return bool
	 */
	private static function process_is_linked_to_building( int $process_id, int $building_id ): bool {
		foreach ( ORGAHB_Building_Links::get_buildings_for_content( 'process', $process_id ) as $link ) {
			if ( (int) $link['building_id'] === $building_id ) {
				return true;
			}
		}
		return false;
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Bundle cache management  (spec §35.2 / §35.3)
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * Fires on save_post. Invalidates the bundle cache for any building linked
	 * to the changed post, and for the building post itself.
	 *
	 * @param int $post_id
	 * @return void
	 */
	public static function on_post_change( int $post_id ): void {
		// Direct building update.
		if ( 'orgahb_building' === get_post_type( $post_id ) ) {
			self::invalidate_bundle_cache( $post_id );
			return;
		}

		// Content CPT change — find all buildings it is linked to.
		$buildings = ORGAHB_Building_Links::get_buildings_for_content_by_post_id( $post_id );
		foreach ( $buildings as $building_id ) {
			self::invalidate_bundle_cache( $building_id );
		}
	}

	/**
	 * Deletes the cached structural bundle for a building.
	 *
	 * @param int $building_id
	 * @return void
	 */
	public static function invalidate_bundle_cache( int $building_id ): void {
		delete_transient( self::BUNDLE_TRANSIENT_PREFIX . $building_id );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Handler: backlinks
	// ────────────────────────────────────────────────────────────────────────────

	/**
	 * GET /items/{id}/backlinks
	 *
	 * Returns all published processes whose hotspot JSON contains a LINK hotspot
	 * targeting this item (by content_id).  Supports the "What links here?"
	 * SiYuan-style backlinks panel in the handbook viewer.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function get_item_backlinks( WP_REST_Request $request ): WP_REST_Response {
		$target_id = (int) $request->get_param( 'id' );

		// Query all published processes that have hotspot JSON meta set.
		$processes = get_posts( array(
			'post_type'      => 'orgahb_process',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => ORGAHB_Metaboxes::META_HOTSPOTS_JSON,
					'value'   => '"target_id":' . $target_id,
					'compare' => 'LIKE',
				),
			),
		) );

		$backlinks = array();
		foreach ( $processes as $pid ) {
			$json = (string) get_post_meta( $pid, ORGAHB_Metaboxes::META_HOTSPOTS_JSON, true );
			$hotspots = json_decode( $json, true );
			if ( ! is_array( $hotspots ) ) {
				continue;
			}
			// Confirm at least one LINK hotspot actually targets this item.
			foreach ( $hotspots as $hs ) {
				if ( isset( $hs['target_id'] ) && (int) $hs['target_id'] === $target_id ) {
					$post = get_post( $pid );
					if ( $post ) {
						$backlinks[] = array(
							'content_id'   => $pid,
							'title'        => $post->post_title,
							'content_type' => 'process',
						);
					}
					break; // one match per process is enough
				}
			}
		}

		return new WP_REST_Response( $backlinks, 200 );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Error factory helpers
	// ────────────────────────────────────────────────────────────────────────────

	/** @return WP_Error */
	private static function forbidden(): WP_Error {
		return new WP_Error(
			'orgahb_forbidden',
			__( 'You are not allowed to perform this action.', 'orgahb-manager' ),
			array( 'status' => 403 )
		);
	}

	/** @return WP_Error */
	private static function not_found( string $message ): WP_Error {
		return new WP_Error( 'orgahb_not_found', $message, array( 'status' => 404 ) );
	}

	/** @return WP_Error */
	private static function bad_request( string $message ): WP_Error {
		return new WP_Error( 'orgahb_bad_request', $message, array( 'status' => 400 ) );
	}

	/** @return WP_Error */
	private static function server_error( string $message ): WP_Error {
		return new WP_Error( 'orgahb_server_error', $message, array( 'status' => 500 ) );
	}
}
