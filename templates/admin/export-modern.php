<?php
defined( 'ABSPATH' ) || exit;

$data = is_array( $data ?? null ) ? $data : array();
$json = $data['json'] ?? '';
$exported_at_label = $data['exported_at_label'] ?? '';
$format_version = $data['format_version'] ?? '';
$forms_exported = (int) ( $data['forms_exported'] ?? 0 );
$logs_exported = (int) ( $data['logs_exported'] ?? 0 );
$recent_log = $data['recent_log'] ?? null;
?>
<div class="wrap"
	data-copy-ok="<?php echo esc_attr__( 'Export JSON copied.', 'crocina-forms' ); ?>"
	data-copy-fail="<?php echo esc_attr__( 'Copy failed. Please select and copy manually.', 'crocina-forms' ); ?>"
	data-partial-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
	data-partial-nonce="<?php echo esc_attr( wp_create_nonce( 'crocina_partial_export' ) ); ?>">
	<h1><?php esc_html_e( 'Export & Import Center', 'crocina-forms' ); ?></h1>
	<hr class="wp-header-end" />
	<p class="description">
		<?php
		printf(
			__( 'Snapshot: %1$s (format %2$s). %3$d forms and %4$d submissions.', 'crocina-forms' ),
			esc_html( $exported_at_label ),
			esc_html( $format_version ),
			absint( $forms_exported ),
			absint( $logs_exported )
		);
		?>
	</p>

	<?php if ( $recent_log ) : ?>
		<div class="notice notice-info">
			<p><?php printf( __( 'Latest log: %1$s submitted on %2$s.', 'crocina-forms' ), esc_html( $recent_log['form_slug'] ?? __( 'unknown form', 'crocina-forms' ) ), esc_html( $recent_log['submitted_at'] ?? '' ) ); ?></p>
		</div>
	<?php endif; ?>

	<div class="metabox-holder" style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start;">
		<div class="postbox" style="flex:1;min-width:300px;">
			<div class="postbox-header">
				<h2 class="hndle"><span><?php esc_html_e( 'Export data', 'crocina-forms' ); ?></span></h2>
			</div>
			<div class="inside">
				<p><?php esc_html_e( 'This JSON snapshot includes your forms, settings, and inbox history.', 'crocina-forms' ); ?></p>

				<h4><?php esc_html_e( 'Select what to export', 'crocina-forms' ); ?></h4>
				<p>
					<label><input type="checkbox" name="crocina_export_cats[]" value="settings" checked /> <?php esc_html_e( 'Global settings (channels, spam, logging)', 'crocina-forms' ); ?></label><br />
					<label><input type="checkbox" name="crocina_export_cats[]" value="forms" checked /> <?php esc_html_e( 'Forms and their field definitions', 'crocina-forms' ); ?></label><br />
					<label><input type="checkbox" name="crocina_export_cats[]" value="logs" checked /> <?php esc_html_e( 'Inbox / submission history', 'crocina-forms' ); ?></label>
				</p>

				<p><strong><?php echo esc_html( number_format_i18n( $forms_exported ) ); ?></strong> <?php esc_html_e( 'forms', 'crocina-forms' ); ?> &middot;
				<strong><?php echo esc_html( number_format_i18n( $logs_exported ) ); ?></strong> <?php esc_html_e( 'submissions', 'crocina-forms' ); ?></p>

				<textarea readonly id="crocina-export-json" style="width:100%;height:200px;font-family:Consolas,Monaco,monospace;font-size:11px;"><?php echo esc_textarea( $json ); ?></textarea>

				<p>
					<button type="button" class="button button-primary crocina-copy-btn" data-target="#crocina-export-json">
						<?php esc_html_e( 'Copy export code', 'crocina-forms' ); ?>
					</button>
					<button type="button" class="button button-secondary crocina-partial-export-btn">
						<?php esc_html_e( 'Export selected only', 'crocina-forms' ); ?>
					</button>
				</p>
				<p class="description"><?php esc_html_e( 'Copy the full JSON or export only selected categories above.', 'crocina-forms' ); ?></p>
				<div class="crocina-export-status" role="status" aria-live="polite"></div>
			</div>
		</div>

		<div class="postbox" style="flex:1;min-width:300px;">
			<div class="postbox-header">
				<h2 class="hndle"><span><?php esc_html_e( 'Import configuration', 'crocina-forms' ); ?></span></h2>
			</div>
			<div class="inside">
				<p><?php esc_html_e( 'Only import JSON from trusted sources.', 'crocina-forms' ); ?></p>

				<?php settings_errors( 'crocina_forms_import' ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="crocina_import_config" />
					<?php wp_nonce_field( 'crocina_import_config', 'crocina_import_nonce' ); ?>
					<textarea name="crocina_import_payload" style="width:100%;height:120px;font-family:Consolas,Monaco,monospace;font-size:11px;" placeholder="<?php esc_attr_e( 'Paste your JSON payload here...', 'crocina-forms' ); ?>"></textarea>
					<p>
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Start import', 'crocina-forms' ); ?></button>
					</p>
					<div class="crocina-import-status" role="status" aria-live="polite"></div>
				</form>
			</div>
		</div>
	</div>
</div>
