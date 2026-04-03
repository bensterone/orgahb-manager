<?php
/**
 * Search index generation and REST endpoint.
 *
 * Spec §26: deterministic, metadata-driven search via a server-generated
 * JSON document list consumed by the Fuse.js client.
 *
 * Two index shapes are produced:
 *
 *   Global index  — all published content + buildings + section terms.
 *     Transient key : orgahb_search_index_global
 *     REST endpoint : GET /orgahb/v1/search/index
 *
 *   Building index — content items linked to a specific building, enriched
 *     with area / local-note context.
 *     Transient key : orgahb_search_index_{building_id}
 *     REST endpoint : GET /orgahb/v1/search/index?building_id={id}
 *
 * Cache is invalidated on any relevant post save or bundle link change
 * (spec §26.7).
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Search index builder and REST handler.
 */
final class ORGAHB_Search {

	/** How long (seconds) the index transient lives before auto-expiry. */
	const CACHE_TTL = DAY_IN_SECONDS;

	/** Transient key for the global index. */
	const TRANSIENT_GLOBAL = 'orgahb_search_index_global';

	/** Prefix for per-building transients. */
	const TRANSIENT_BUILDING_PREFIX = 'orgahb_search_index_';

	// ── Hook registration ─────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function init(): void {
		// Invalidate on content publish / un-publish / update.
		add_action( 'save_post', array( self::class, 'on_post_change' ), 10, 3 );

		// Invalidate when bundle links change (custom action fired by
		// ORGAHB_Building_Links after write/delete operations).
		add_action( 'orgahb_building_links_changed', array( self::class, 'on_links_changed' ), 10, 1 );

		// REST route.
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	// ── REST ──────────────────────────────────────────────────────────────────

	/**
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			ORGAHB_REST_API::NAMESPACE,
			'/search/index',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_index' ),
				'permission_callback' => array( self::class, 'can_read' ),
				'args'                => array(
					'building_id' => array(
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * @return bool
	 */
	public static function can_read(): bool {
		return current_user_can( 'read_orgahb_content' );
	}

	/**
	 * GET /orgahb/v1/search/index[?building_id={id}]
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function get_index( WP_REST_Request $request ): WP_REST_Response {
		$building_id = (int) $request->get_param( 'building_id' );

		$index = $building_id > 0
			? self::get_building_index( $building_id )
			: self::get_global_index();

		return rest_ensure_response( $index );
	}

	// ── Public index accessors ────────────────────────────────────────────────

	/**
	 * Returns (and builds if necessary) the global search index.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function get_global_index(): array {
		$cached = get_transient( self::TRANSIENT_GLOBAL );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$index = self::build_global_index();
		set_transient( self::TRANSIENT_GLOBAL, $index, self::CACHE_TTL );
		return $index;
	}

	/**
	 * Returns (and builds if necessary) the building-scoped search index.
	 *
	 * @param int $building_id
	 * @return list<array<string, mixed>>
	 */
	public static function get_building_index( int $building_id ): array {
		$key    = self::TRANSIENT_BUILDING_PREFIX . $building_id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$index = self::build_building_index( $building_id );
		set_transient( $key, $index, self::CACHE_TTL );
		return $index;
	}

	// ── Cache invalidation ────────────────────────────────────────────────────

	/**
	 * Fires on save_post. Invalidates caches for CPTs we index.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 * @param bool    $update
	 * @return void
	 */
	public static function on_post_change( int $post_id, WP_Post $post, bool $update ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$watched = array( 'orgahb_page', 'orgahb_process', 'orgahb_document', 'orgahb_building' );
		if ( ! in_array( $post->post_type, $watched, true ) ) {
			return;
		}

		delete_transient( self::TRANSIENT_GLOBAL );

		if ( 'orgahb_building' === $post->post_type ) {
			delete_transient( self::TRANSIENT_BUILDING_PREFIX . $post_id );
		} else {
			$type_map = array_flip( ORGAHB_Building_Links::CPT_MAP );
			$ctype    = $type_map[ $post->post_type ] ?? '';
			if ( $ctype ) {
				$rows = ORGAHB_Building_Links::get_buildings_for_content( $ctype, $post_id );
				foreach ( $rows as $row ) {
					delete_transient( self::TRANSIENT_BUILDING_PREFIX . (int) $row['building_id'] );
				}
			}
		}
	}

	/**
	 * Fires on orgahb_building_links_changed. Invalidates the building cache.
	 *
	 * @param int $building_id
	 * @return void
	 */
	public static function on_links_changed( int $building_id ): void {
		delete_transient( self::TRANSIENT_GLOBAL );
		delete_transient( self::TRANSIENT_BUILDING_PREFIX . $building_id );
	}

	// ── Index builders ────────────────────────────────────────────────────────

	/**
	 * Builds the full global search index.
	 *
	 * Document shape:
	 *   id, type, title, aliases, excerpt, section_labels,
	 *   version_label, hotspot_labels, filename
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function build_global_index(): array {
		$docs = array();

		// Content CPTs.
		foreach ( ORGAHB_Building_Links::CPT_MAP as $content_type => $cpt_slug ) {
			$posts = get_posts( array(
				'post_type'      => $cpt_slug,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			) );

			foreach ( $posts as $post ) {
				$docs[] = self::index_content_post( $post, $content_type );
			}
		}

		// Buildings.
		$buildings = get_posts( array(
			'post_type'      => 'orgahb_building',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		) );

		foreach ( $buildings as $building ) {
			$docs[] = self::index_building( $building );
		}

		// Section taxonomy terms.
		$terms = get_terms( array(
			'taxonomy'   => 'orgahb_section',
			'hide_empty' => false,
		) );

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$docs[] = array(
					'id'      => 'term_' . $term->term_id,
					'type'    => 'section',
					'title'   => $term->name,
					'aliases' => '',
					'excerpt' => $term->description,
				);
			}
		}

		return array_values( $docs );
	}

	/**
	 * Builds a building-scoped index — only items in the building's bundle,
	 * enriched with area label and local note from the link row.
	 *
	 * @param int $building_id
	 * @return list<array<string, mixed>>
	 */
	private static function build_building_index( int $building_id ): array {
		$links = ORGAHB_Building_Links::get_for_building( $building_id );
		$areas = ORGAHB_Buildings::get_areas( $building_id );

		$area_labels = array();
		foreach ( $areas as $area ) {
			$area_labels[ $area['key'] ] = $area['label'];
		}

		$docs     = array();
		$seen_key = array(); // track doc_key → index in $docs for area appending.

		foreach ( $links as $link ) {
			$content_type = $link['content_type'];
			$content_id   = (int) $link['content_id'];
			$cpt          = ORGAHB_Building_Links::CPT_MAP[ $content_type ] ?? '';

			if ( ! $cpt ) {
				continue;
			}

			$post = get_post( $content_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}

			$doc_key    = $content_type . '_' . $content_id;
			$area_label = $area_labels[ $link['area_key'] ] ?? $link['area_key'];

			if ( isset( $seen_key[ $doc_key ] ) ) {
				// Item appears in multiple areas — append area label only.
				$docs[ $seen_key[ $doc_key ] ]['area_label'] .= ', ' . $area_label;
				continue;
			}

			$doc               = self::index_content_post( $post, $content_type );
			$doc['area_key']   = $link['area_key'];
			$doc['area_label'] = $area_label;
			$doc['local_note'] = (string) ( $link['local_note'] ?? '' );
			$doc['is_featured'] = (bool) $link['is_featured'];
			$doc['sort_order']  = (int) $link['sort_order'];

			$idx              = count( $docs );
			$seen_key[ $doc_key ] = $idx;
			$docs[]           = $doc;
		}

		return array_values( $docs );
	}

	// ── Per-document field extractors ─────────────────────────────────────────

	/**
	 * Builds a search document for a content CPT post.
	 *
	 * @param WP_Post $post
	 * @param string  $content_type  'page'|'process'|'document'
	 * @return array<string, mixed>
	 */
	private static function index_content_post( WP_Post $post, string $content_type ): array {
		$id = $post->ID;

		$aliases       = (string) get_post_meta( $id, ORGAHB_Buildings::META_SEARCH_ALIASES, true );
		$version_label = (string) get_post_meta( $id, ORGAHB_Metaboxes::META_VERSION_LABEL, true );

		$terms = get_the_terms( $id, 'orgahb_section' );
		$section_labels = is_array( $terms )
			? implode( ' ', wp_list_pluck( $terms, 'name' ) )
			: '';

		$hotspot_labels = '';
		$filename       = '';

		if ( 'process' === $content_type ) {
			$hotspot_labels = self::extract_hotspot_labels( $id );
		}

		if ( 'document' === $content_type ) {
			$att_id = (int) get_post_meta( $id, ORGAHB_Metaboxes::META_CURRENT_ATTACHMENT_ID, true );
			if ( $att_id ) {
				$filename = get_the_title( $att_id );
			}
		}

		$excerpt = '';
		if ( 'page' === $content_type && has_excerpt( $id ) ) {
			$excerpt = wp_strip_all_tags( get_the_excerpt( $post ) );
		}

		return array(
			'id'             => $id,
			'type'           => $content_type,
			'title'          => $post->post_title,
			'aliases'        => $aliases,
			'excerpt'        => $excerpt,
			'section_labels' => $section_labels,
			'version_label'  => $version_label,
			'hotspot_labels' => $hotspot_labels,
			'filename'       => $filename,
		);
	}

	/**
	 * Builds a search document for a building.
	 *
	 * @param WP_Post $post
	 * @return array<string, mixed>
	 */
	private static function index_building( WP_Post $post ): array {
		$id          = $post->ID;
		$areas       = ORGAHB_Buildings::get_areas( $id );
		$area_labels = implode( ' ', array_column( $areas, 'label' ) );

		return array(
			'id'          => $id,
			'type'        => 'building',
			'title'       => $post->post_title,
			'aliases'     => (string) get_post_meta( $id, ORGAHB_Buildings::META_SEARCH_ALIASES, true ),
			'code'        => ORGAHB_Buildings::get_code( $id ),
			'address'     => ORGAHB_Buildings::get_address( $id ),
			'area_labels' => $area_labels,
		);
	}

	/**
	 * Extracts space-separated hotspot labels from the process JSON.
	 *
	 * @param int $process_id
	 * @return string
	 */
	private static function extract_hotspot_labels( int $process_id ): string {
		$json = (string) get_post_meta( $process_id, ORGAHB_Metaboxes::META_HOTSPOTS_JSON, true );
		if ( '' === $json ) {
			return '';
		}

		$hotspots = json_decode( $json, true );
		if ( ! is_array( $hotspots ) ) {
			return '';
		}

		$labels = array();
		foreach ( $hotspots as $hs ) {
			$label = trim( (string) ( $hs['label'] ?? '' ) );
			if ( '' !== $label ) {
				$labels[] = $label;
			}
			// Also index comma-separated aliases (spec §26.5).
			$aliases = trim( (string) ( $hs['aliases'] ?? '' ) );
			if ( '' !== $aliases ) {
				$labels[] = $aliases;
			}
		}

		return implode( ' ', $labels );
	}
}
