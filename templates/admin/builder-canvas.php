<?php
defined( 'ABSPATH' ) || exit;

$data = is_array( $data ?? null ) ? $data : array();
$fields = is_array( $data['fields'] ?? null ) ? $data['fields'] : array();
$current_slug = $data['current_slug'] ?? '';
$field_types = $data['field_types'] ?? array();
$post = $data['post'] ?? null;
$render_field_card    = $data['render_field_card'] ?? null;
$render_design        = $data['render_design'] ?? null;
$render_notifications = $data['render_notifications'] ?? null;
$saved_theme          = 'modern';
if ( $post instanceof WP_Post ) {
	$design_meta = (array) get_post_meta( $post->ID, 'crocina_form_design', true );
	$allowed_themes = array( 'modern', 'classic', 'minimal' );
	if ( ! empty( $design_meta['theme'] ) && in_array( $design_meta['theme'], $allowed_themes, true ) ) {
		$saved_theme = $design_meta['theme'];
	}
}
?>
<div class="crocina-builder-wrapper">
	<aside class="crocina-builder-sidebar">
		<h4><?php esc_html_e( 'Add fields', 'crocina-forms' ); ?></h4>
		<div class="crocina-field-buttons">
			<?php foreach ( $field_types as $type => $label ) : ?>
				<button type="button" class="crocina-add-btn" data-type="<?php echo esc_attr( $type ); ?>">
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
					<?php echo esc_html( $label ); ?>
				</button>
			<?php endforeach; ?>
		</div>
		<div class="crocina-builder-shortcuts">
			<button type="button" class="crocina-add-text-btn button crocina-add-field">
				<span class="dashicons dashicons-editor-textcolor" aria-hidden="true"></span>
				<?php esc_html_e( 'Quick add text', 'crocina-forms' ); ?>
			</button>
		</div>
		<div class="crocina-builder-help">
			<p><?php esc_html_e( 'Click a field type or drag it onto the canvas. Drag card handles to reorder.', 'crocina-forms' ); ?></p>
		</div>
	</aside>

	<main class="crocina-builder-canvas">
		<div class="crocina-canvas-header">
			<label class="screen-reader-text" for="crocina-form-slug"><?php esc_html_e( 'Form slug', 'crocina-forms' ); ?></label>
			<input
				id="crocina-form-slug"
				type="text"
				name="crocina_form_slug"
				placeholder="<?php esc_attr_e( 'Form slug (identifier)...', 'crocina-forms' ); ?>"
				value="<?php echo esc_attr( $current_slug ); ?>"
			/>

			<div class="crocina-template-switcher">
				<span class="crocina-template-switcher-label"><?php esc_html_e( 'Template:', 'crocina-forms' ); ?></span>
				<?php
				$templates = array(
					'default'  => __( 'Default', 'crocina-forms' ),
					'card'     => __( 'Card', 'crocina-forms' ),
					'minimal'  => __( 'Minimal', 'crocina-forms' ),
					'bordered' => __( 'Bordered', 'crocina-forms' ),
					'shadow'   => __( 'Shadow', 'crocina-forms' ),
				);
				$saved_template = 'default';
				if ( $post instanceof WP_Post && ! empty( $design_meta['template'] ) ) {
					$saved_template = $design_meta['template'];
				}
				$template_icons = array(
					'default'  => 'editor-alignleft',
					'card'     => 'welcome-widgets-menus',
					'minimal'  => 'editor-aligncenter',
					'bordered' => 'editor-table',
					'shadow'   => 'cloud',
				);
				foreach ( $templates as $value => $label ) :
					$is_active = $saved_template === $value;
					?>
					<button type="button"
						class="crocina-template-switcher-btn<?php echo $is_active ? ' is-active' : ''; ?>"
						data-template="<?php echo esc_attr( $value ); ?>">
						<span class="dashicons dashicons-<?php echo esc_attr( $template_icons[ $value ] ); ?>" aria-hidden="true"></span>
						<span><?php echo esc_html( $label ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>

		<div class="crocina-builder-toolbar">
			<button type="button" class="crocina-undo-btn button" disabled title="<?php esc_attr_e( 'Undo (Ctrl+Z)', 'crocina-forms' ); ?>">
				<span class="dashicons dashicons-undo" aria-hidden="true"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Undo', 'crocina-forms' ); ?></span>
			</button>
			<button type="button" class="crocina-redo-btn button" disabled title="<?php esc_attr_e( 'Redo (Ctrl+Shift+Z)', 'crocina-forms' ); ?>">
				<span class="dashicons dashicons-redo" aria-hidden="true"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Redo', 'crocina-forms' ); ?></span>
			</button>
			<span class="crocina-toolbar-spacer"></span>
			<button type="button" class="crocina-quick-save button button-primary" title="<?php esc_attr_e( 'Save form (Ctrl+S)', 'crocina-forms' ); ?>">
				<span class="dashicons dashicons-save" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Save', 'crocina-forms' ); ?></span>
			</button>
		</div>

		<div id="crocina-fields-sortable" class="crocina-field-cards crocina-fields-grid-admin">
			<?php foreach ( $fields as $field ) : ?>
				<?php
				if ( is_callable( $render_field_card ) ) {
					$render_field_card( $field );
				}
				?>
			<?php endforeach; ?>
		</div>

		<div class="crocina-empty-state<?php echo empty( $fields ) ? '' : ' is-hidden'; ?>">
			<span class="dashicons dashicons-edit" aria-hidden="true"></span>
			<p><?php esc_html_e( 'Select a field from the left to start building your form.', 'crocina-forms' ); ?></p>
		</div>

		<div class="crocina-honeypot-indicator">
			<span class="dashicons dashicons-shield" aria-hidden="true"></span>
			<?php esc_html_e( 'Spam protection is enabled for this form.', 'crocina-forms' ); ?>
		</div>
	</main>
</div>

<div id="crocina-field-template" style="display:none;">
	<?php
	if ( is_callable( $render_field_card ) ) {
		$render_field_card( array(), true );
	}
	?>
</div>

<div class="crocina-form-settings-section">
	<h3><?php esc_html_e( 'Form settings', 'crocina-forms' ); ?></h3>
	<div class="crocina-form-settings-grid">
		<div class="crocina-form-settings-card">
			<?php
			if ( $post instanceof WP_Post && is_callable( $render_design ) ) {
				call_user_func( $render_design, $post );
			}
			?>
		</div>
		<div class="crocina-form-settings-card">
			<?php
			if ( $post instanceof WP_Post && is_callable( $render_notifications ) ) {
				call_user_func( $render_notifications, $post );
			}
			?>
		</div>

		<div class="crocina-form-settings-card" id="crocina-export-card">
			<div class="crocina-export-inline">
				<h4 class="crocina-export-inline-title">
					<span class="dashicons dashicons-database-export" aria-hidden="true"></span>
					<?php esc_html_e( 'Export / Import', 'crocina-forms' ); ?>
				</h4>
				<p class="crocina-export-inline-desc">
					<?php esc_html_e( 'Quick backup or transfer this single form as JSON.', 'crocina-forms' ); ?>
				</p>

				<?php $export_nonce = wp_create_nonce( 'crocina_export_form' ); ?>
				<div class="crocina-export-inline-actions">
					<button type="button" class="button crocina-export-form-btn" data-form-id="<?php echo esc_attr( $post->ID ?? 0 ); ?>" data-nonce="<?php echo esc_attr( $export_nonce ); ?>">
						<span class="dashicons dashicons-download" aria-hidden="true"></span>
						<?php esc_html_e( 'Export this form', 'crocina-forms' ); ?>
					</button>
				</div>

				<div class="crocina-export-inline-area" style="display:none;">
					<textarea class="crocina-export-inline-json" rows="5" readonly placeholder="<?php esc_attr_e( 'Click export above to generate JSON…', 'crocina-forms' ); ?>"></textarea>
					<div class="crocina-export-inline-buttons">
						<button type="button" class="button button-primary crocina-copy-json-btn">
							<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
							<?php esc_html_e( 'Copy', 'crocina-forms' ); ?>
						</button>
						<button type="button" class="button crocina-download-json-btn">
							<span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
							<?php esc_html_e( 'Download .json', 'crocina-forms' ); ?>
						</button>
					</div>
				</div>

				<div class="crocina-import-inline-divider"><span><?php esc_html_e( 'or', 'crocina-forms' ); ?></span></div>

				<div class="crocina-import-inline-area">
					<textarea class="crocina-import-inline-json" rows="4" placeholder="<?php esc_attr_e( 'Paste JSON to import into this form…', 'crocina-forms' ); ?>" data-import-nonce="<?php echo esc_attr( wp_create_nonce( 'crocina_import_single_form' ) ); ?>"></textarea>
					<button type="button" class="button crocina-import-single-btn" data-form-id="<?php echo esc_attr( $post->ID ?? 0 ); ?>">
						<span class="dashicons dashicons-upload" aria-hidden="true"></span>
						<?php esc_html_e( 'Import into this form', 'crocina-forms' ); ?>
					</button>
					<div class="crocina-import-status" role="status" aria-live="polite"></div>
				</div>
			</div>
		</div>
	</div>
</div>
