<?php
/**
 * Plugin activation, deactivation, and uninstall handler.
 *
 * Handles the full WordPress plugin lifecycle:
 *   - activate()    — requirements check, DB migration, roles, rewrite flush, cron
 *   - deactivate()  — cron teardown, rewrite flush
 *   - uninstall()   — called from uninstall.php; drops all data when setting allows
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin lifecycle events and database migrations.
 */
final class ORGAHB_Install {

	/**
	 * Plugin schema version stored in wp_options.
	 * Increment this constant when migrate_x_y_z() methods are added.
	 *
	 * The constant ORGAHB_DB_VERSION in the main file drives the initial
	 * option write; this class re-reads that constant for comparison.
	 */
	private const SCHEMA_VERSION = '1.0.0';

	/**
	 * Custom tables owned by this plugin.
	 * Listed here so activation and uninstall use one shared source of truth.
	 *
	 * @var string[]
	 */
	private const TABLES = array(
		'orgahb_acknowledgments',
		'orgahb_executions',
		'orgahb_observations',
		'orgahb_audit_events',
		'orgahb_building_links',
	);

	// ── Lifecycle entry points ────────────────────────────────────────────────

	/**
	 * Runs on plugin activation.
	 *
	 * Sequence:
	 *  1. Check server requirements — deactivates and wp_die() on failure.
	 *  2. Run DB migrations (idempotent via dbDelta).
	 *  3. Register custom roles and capabilities.
	 *  4. Register CPTs + taxonomy so rewrite rules are flush-safe.
	 *  5. Flush rewrite rules once.
	 *  6. Schedule daily cron event if not already present.
	 *  7. Register default options (add_option is a no-op if they exist).
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::check_requirements();
		self::run_migrations();
		ORGAHB_Capabilities::register_roles();

		// CPTs and taxonomy must be registered before flushing rewrite rules.
		ORGAHB_Taxonomy::register_all();
		ORGAHB_CPT::register_all();
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( 'orgahb_daily_review_check' ) ) {
			wp_schedule_event( time(), 'daily', 'orgahb_daily_review_check' );
		}

		self::register_default_options();
		update_option( 'orgahb_db_version', ORGAHB_DB_VERSION );
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * Does NOT remove data — that belongs in uninstall.php / uninstall().
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( 'orgahb_daily_review_check' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'orgahb_daily_review_check' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Runs on plugin deletion — called from root uninstall.php.
	 *
	 * Only drops data when the admin has explicitly enabled
	 * "delete plugin data on uninstall" in plugin settings.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		if ( ! get_option( 'orgahb_delete_data_on_uninstall', false ) ) {
			return;
		}

		global $wpdb;

		// Drop all custom tables in reverse dependency order.
		foreach ( array_reverse( self::TABLES ) as $table ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		ORGAHB_Capabilities::remove_roles();

		// Remove plugin options.
		$options = array(
			'orgahb_db_version',
			'orgahb_delete_data_on_uninstall',
			'orgahb_review_reminder_days',
			'orgahb_require_reviewer_comment',
			'orgahb_qr_base_url',
		);
		foreach ( $options as $option ) {
			delete_option( $option );
		}

		// Bust all plugin transients (search index + bundle cache).
		// Direct query because plugin classes are not loaded in uninstall context.
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_orgahb_%'
			    OR option_name LIKE '_transient_timeout_orgahb_%'"
		);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Verifies minimum WordPress and PHP version requirements.
	 *
	 * Deactivates the plugin and calls wp_die() if requirements are not met.
	 *
	 * @return void
	 */
	private static function check_requirements(): void {
		global $wp_version;

		if ( version_compare( $wp_version, ORGAHB_MIN_WP, '<' ) ) {
			deactivate_plugins( plugin_basename( ORGAHB_PLUGIN_FILE ) );
			wp_die(
				sprintf(
					/* translators: 1: required WordPress version 2: current WordPress version */
					esc_html__( 'OrgaHB Manager requires WordPress %1$s or higher. You are running %2$s.', 'orgahb-manager' ),
					esc_html( ORGAHB_MIN_WP ),
					esc_html( $wp_version )
				)
			);
		}

		if ( version_compare( PHP_VERSION, ORGAHB_MIN_PHP, '<' ) ) {
			deactivate_plugins( plugin_basename( ORGAHB_PLUGIN_FILE ) );
			wp_die(
				sprintf(
					/* translators: 1: required PHP version 2: current PHP version */
					esc_html__( 'OrgaHB Manager requires PHP %1$s or higher. You are running %2$s.', 'orgahb-manager' ),
					esc_html( ORGAHB_MIN_PHP ),
					esc_html( PHP_VERSION )
				)
			);
		}
	}

	/**
	 * Runs all DB migrations in version order.
	 *
	 * Each migrate_x_y_z() method is idempotent — dbDelta() only alters
	 * existing tables when the schema differs.
	 *
	 * @return void
	 */
	private static function run_migrations(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		self::migrate_1_0_0();
	}

	/**
	 * Schema v1.0.0 — creates all five custom tables.
	 *
	 * Tables:
	 *   orgahb_acknowledgments — immutable acknowledgment events (spec §18.1)
	 *   orgahb_executions      — immutable field evidence events  (spec §18.2)
	 *   orgahb_observations    — lightweight building notes        (spec §18.3)
	 *   orgahb_audit_events    — workflow and governance audit log (spec §18.4)
	 *   orgahb_building_links  — building bundle relation table    (spec §18.5)
	 *
	 * user_id and actor columns are nullable so that deleting a WordPress user
	 * sets the column to NULL while the historical_*_label preserves the display
	 * name for audit-trail purposes (spec §12.8).
	 *
	 * @return void
	 */
	private static function migrate_1_0_0(): void {
		global $wpdb;

		$cc = $wpdb->get_charset_collate();
		$p  = $wpdb->prefix;

		// ── Acknowledgments ───────────────────────────────────────────────────
		// One row per user-per-revision acknowledgment event.
		// No UNIQUE key on (user_id, post_id) — a user may acknowledge different
		// approved revisions of the same post over time.
		dbDelta(
			"CREATE TABLE {$p}orgahb_acknowledgments (
				id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id               BIGINT UNSIGNED NOT NULL,
				user_id               BIGINT UNSIGNED NULL,
				historical_user_label VARCHAR(255)    NULL,
				acknowledged_at       DATETIME(6)     NOT NULL,
				post_revision_id      BIGINT UNSIGNED NOT NULL,
				post_version_label    VARCHAR(50)     NULL,
				queue_uuid            CHAR(36)        NULL,
				source                VARCHAR(20)     NOT NULL DEFAULT 'ui',
				created_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_post_user (post_id, user_id),
				KEY idx_post_revision (post_id, post_revision_id),
				KEY idx_acknowledged_at (acknowledged_at),
				KEY idx_user_ack (user_id, acknowledged_at),
				KEY idx_queue_uuid (queue_uuid)
			) ENGINE=InnoDB {$cc};"
		);

		// ── Executions ────────────────────────────────────────────────────────
		// Immutable field evidence: one row per hotspot tap + outcome.
		// outcome values: completed | issue_noted | blocked | not_applicable | escalated
		dbDelta(
			"CREATE TABLE {$p}orgahb_executions (
				id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id               BIGINT UNSIGNED NOT NULL,
				hotspot_id            VARCHAR(64)     NOT NULL,
				building_id           BIGINT UNSIGNED NOT NULL,
				area_key              VARCHAR(100)    NULL,
				user_id               BIGINT UNSIGNED NULL,
				historical_user_label VARCHAR(255)    NULL,
				outcome               VARCHAR(32)     NOT NULL,
				executed_at           DATETIME(6)     NOT NULL,
				note                  LONGTEXT        NULL,
				post_revision_id      BIGINT UNSIGNED NOT NULL,
				post_version_label    VARCHAR(50)     NULL,
				queue_uuid            CHAR(36)        NULL,
				source                VARCHAR(20)     NOT NULL DEFAULT 'ui',
				created_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_post_hotspot (post_id, hotspot_id),
				KEY idx_building_exec (building_id, executed_at),
				KEY idx_area_exec (building_id, area_key, executed_at),
				KEY idx_user_exec (user_id, executed_at),
				KEY idx_revision (post_revision_id),
				KEY idx_queue_uuid (queue_uuid),
				KEY idx_outcome (outcome)
			) ENGINE=InnoDB {$cc};"
		);

		// ── Observations ──────────────────────────────────────────────────────
		// Lightweight building-level operational notes (spec §7.5).
		// status values: open | in_progress | resolved | closed
		dbDelta(
			"CREATE TABLE {$p}orgahb_observations (
				id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				building_id                BIGINT UNSIGNED NOT NULL,
				area_key                   VARCHAR(100)    NULL,
				author_user_id             BIGINT UNSIGNED NULL,
				historical_author_label    VARCHAR(255)    NULL,
				category                   VARCHAR(50)     NOT NULL,
				status                     VARCHAR(20)     NOT NULL DEFAULT 'open',
				summary                    VARCHAR(255)    NOT NULL,
				details                    LONGTEXT        NULL,
				external_reference         VARCHAR(255)    NULL,
				queue_uuid                 CHAR(36)        NULL,
				resolved_at                DATETIME(6)     NULL,
				resolved_by_user_id        BIGINT UNSIGNED NULL,
				historical_resolver_label  VARCHAR(255)    NULL,
				source                     VARCHAR(20)     NOT NULL DEFAULT 'manual',
				created_at                 DATETIME(6)     NOT NULL,
				recorded_at                TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_building_status (building_id, status),
				KEY idx_area_status (building_id, area_key, status),
				KEY idx_created_at (created_at),
				KEY idx_external_reference (external_reference),
				KEY idx_queue_uuid (queue_uuid)
			) ENGINE=InnoDB {$cc};"
		);

		// ── Audit events ──────────────────────────────────────────────────────
		// Workflow, governance, and administrative audit trail (spec §18.4).
		dbDelta(
			"CREATE TABLE {$p}orgahb_audit_events (
				id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id                  BIGINT UNSIGNED NULL,
				actor_user_id            BIGINT UNSIGNED NULL,
				historical_actor_label   VARCHAR(255)    NULL,
				event_type               VARCHAR(50)     NOT NULL,
				from_status              VARCHAR(20)     NULL,
				to_status                VARCHAR(20)     NULL,
				post_revision_id         BIGINT UNSIGNED NULL,
				comment_text             LONGTEXT        NULL,
				metadata_json            LONGTEXT        NULL,
				occurred_at              DATETIME(6)     NOT NULL,
				created_at               TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY idx_post_event (post_id, event_type),
				KEY idx_occurred_at (occurred_at),
				KEY idx_actor (actor_user_id, occurred_at)
			) ENGINE=InnoDB {$cc};"
		);

		// ── Building links ────────────────────────────────────────────────────
		// Building bundle relation table — ties content items to a building + area
		// (spec §15, §18.5).
		// content_type values: page | process | document
		dbDelta(
			"CREATE TABLE {$p}orgahb_building_links (
				id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				building_id             BIGINT UNSIGNED NOT NULL,
				area_key                VARCHAR(100)    NOT NULL DEFAULT 'general',
				content_type            VARCHAR(20)     NOT NULL,
				content_id              BIGINT UNSIGNED NOT NULL,
				sort_order              INT             NOT NULL DEFAULT 0,
				is_featured             TINYINT(1)      NOT NULL DEFAULT 0,
				local_note              VARCHAR(255)    NULL,
				advisory_interval_label VARCHAR(100)    NULL,
				created_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_building_area_content (building_id, area_key, content_type, content_id),
				KEY idx_building_area (building_id, area_key, sort_order),
				KEY idx_content_lookup (content_type, content_id),
				KEY idx_featured (building_id, is_featured, sort_order)
			) ENGINE=InnoDB {$cc};"
		);
	}

	/**
	 * Writes plugin default options using add_option() which is a no-op
	 * if the option already exists — safe to call on every activation.
	 *
	 * @return void
	 */
	private static function register_default_options(): void {
		// When true, uninstall.php will drop all plugin data and tables.
		// Intentionally off by default — data deletion must be an explicit choice.
		add_option( 'orgahb_delete_data_on_uninstall', false );

		// Number of days before a review date triggers a reminder notification.
		add_option( 'orgahb_review_reminder_days', 14 );

		// Return-for-revision reviewer comment requirement.
		add_option( 'orgahb_require_reviewer_comment', true );

		// Base URL used when generating building QR codes.
		// Defaults to the WordPress home URL; overridable via settings screen.
		add_option( 'orgahb_qr_base_url', home_url( '/' ) );
	}
}
