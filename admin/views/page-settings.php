<?php
/**
 * Admin view: Plugin Settings.
 *
 * Rendered by ORGAHB_Admin::render_settings_page().
 * Capability check (manage_options) is performed by the caller.
 *
 * @package OrgaHB_Manager
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Handbook Settings', 'orgahb-manager' ); ?></h1>

	<?php settings_errors( ORGAHB_Settings::GROUP ); ?>

	<form method="post" action="options.php">
		<?php settings_fields( ORGAHB_Settings::GROUP ); ?>

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row">
					<label for="<?php echo esc_attr( ORGAHB_Settings::OPT_QR_BASE_URL ); ?>">
						<?php esc_html_e( 'QR Code Base URL', 'orgahb-manager' ); ?>
					</label>
				</th>
				<td>
					<input type="url"
					       id="<?php echo esc_attr( ORGAHB_Settings::OPT_QR_BASE_URL ); ?>"
					       name="<?php echo esc_attr( ORGAHB_Settings::OPT_QR_BASE_URL ); ?>"
					       value="<?php echo esc_url( ORGAHB_Settings::qr_base_url() ); ?>"
					       class="regular-text">
					<p class="description">
						<?php esc_html_e(
							'Base URL for building QR landing pages. QR codes will link to {base_url}b/{token}. Defaults to the site home URL.',
							'orgahb-manager'
						); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="<?php echo esc_attr( ORGAHB_Settings::OPT_REMINDER_DAYS ); ?>">
						<?php esc_html_e( 'Review Reminder Days', 'orgahb-manager' ); ?>
					</label>
				</th>
				<td>
					<input type="number"
					       id="<?php echo esc_attr( ORGAHB_Settings::OPT_REMINDER_DAYS ); ?>"
					       name="<?php echo esc_attr( ORGAHB_Settings::OPT_REMINDER_DAYS ); ?>"
					       value="<?php echo esc_attr( (string) ORGAHB_Settings::review_reminder_days() ); ?>"
					       min="1" max="365" class="small-text">
					<p class="description">
						<?php esc_html_e(
							'Number of days before a content review date at which reminder notifications are sent.',
							'orgahb-manager'
						); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<?php esc_html_e( 'Require Reviewer Comment', 'orgahb-manager' ); ?>
				</th>
				<td>
					<fieldset>
						<label>
							<input type="checkbox"
							       name="<?php echo esc_attr( ORGAHB_Settings::OPT_REQUIRE_COMMENT ); ?>"
							       value="1"
							       <?php checked( ORGAHB_Settings::require_reviewer_comment() ); ?>>
							<?php esc_html_e(
								'Reviewers must supply a comment when returning content for revision.',
								'orgahb-manager'
							); ?>
						</label>
					</fieldset>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<?php esc_html_e( 'Delete Data on Uninstall', 'orgahb-manager' ); ?>
				</th>
				<td>
					<fieldset>
						<label>
							<input type="checkbox"
							       name="<?php echo esc_attr( ORGAHB_Settings::OPT_DELETE_DATA ); ?>"
							       value="1"
							       <?php checked( ORGAHB_Settings::delete_data_on_uninstall() ); ?>>
							<?php esc_html_e(
								'Permanently delete all plugin data (custom tables, post meta, options) when the plugin is uninstalled.',
								'orgahb-manager'
							); ?>
						</label>
						<p class="description">
							<?php esc_html_e(
								'Warning: this action cannot be undone. Disabled by default.',
								'orgahb-manager'
							); ?>
						</p>
					</fieldset>
				</td>
			</tr>

		</table>

		<?php submit_button(); ?>
	</form>
</div>
