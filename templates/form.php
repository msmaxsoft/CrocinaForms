<?php
defined( 'ABSPATH' ) || exit;

$data            = is_array( $data ?? null ) ? $data : array();
$form            = $data['form'] ?? null;
$fields          = $data['fields'] ?? array();
$design          = $data['design'] ?? array();
$custom_css      = is_string( $design['custom_css'] ?? '' ) ? trim( $design['custom_css'] ) : '';
$settings        = $data['settings'] ?? array();
$status          = $data['status'] ?? '';
$message         = $data['message'] ?? '';
$submitted_at    = $data['submitted_at'] ?? 0;
$button_style    = $data['button_style'] ?? '';
$form_css_vars   = $data['form_css_vars'] ?? '';
$has_file        = ! empty( $data['has_file'] );
$ajax_nonce      = $data['ajax_nonce'] ?? '';
$honeypot_name   = $data['honeypot_name'] ?? '';
$return_url      = $data['return_url'] ?? '';
$current_page_id = $data['current_page_id'] ?? 0;
$ajax_enabled    = isset( $data['ajax_enabled'] ) ? (bool) $data['ajax_enabled'] : true;
$form_id         = $form->ID ?? 0;
$form_id_attr    = esc_attr( $form_id );
$status_attr     = esc_attr( $status );
$message_attr    = esc_attr( $message );
$form_css_class  = sanitize_html_class( $data['form_css_class'] ?? '' );
$theme_class     = $form_css_class ? ' ' . esc_attr( $form_css_class ) : '';
$show_footer     = isset( $data['show_footer'] ) ? (bool) $data['show_footer'] : true;
$use_primary_color = ! empty( $data['use_primary_color'] );

/* Max upload size in bytes — used client-side to reject oversized files before upload. */
$max_upload_mb    = max( 1, absint( $settings['attachments_max_mb'] ?? 5 ) );
$max_upload_bytes = $max_upload_mb * 1024 * 1024;
?>
<div class="crocina-form crocina-form-<?php echo $form_id_attr; ?><?php echo $theme_class; ?>" data-crocina-status="<?php echo $status_attr; ?>" data-crocina-message="<?php echo $message_attr; ?>" aria-live="polite"<?php echo $form_css_vars ? ' style="' . esc_attr( $form_css_vars ) . '"' : ''; ?>>
	<?php
	if ( ! empty( $status ) && $message ) {
		echo $this->render( 'notice', array(
			'status'       => $status,
			'message'      => $message,
			'submitted_at' => $submitted_at,
			'settings'     => $settings,
		) );
	}
	?>
	<form method="post" novalidate class="crocina-form__inner" data-crocina-ajax="<?php echo esc_attr( $ajax_enabled ? '1' : '0' ); ?>" data-crocina-max-upload="<?php echo esc_attr( $max_upload_bytes ); ?>"<?php echo $has_file ? ' enctype="multipart/form-data"' : ''; ?>>
		<div class="crocina-upload-progress" style="display:none;">
			<div class="crocina-upload-progress-label"><?php esc_html_e( 'Uploading file…', 'crocina-forms' ); ?></div>
			<div class="crocina-upload-progress-track">
				<div class="crocina-upload-progress-fill" style="width:0%;"></div>
			</div>
			<div class="crocina-upload-progress-pct">0%</div>
		</div>
		<input type="hidden" name="action" value="crocina_submit_form">
		<input type="hidden" name="crocina_form_id" value="<?php echo $form_id_attr; ?>">
		<input type="hidden" name="crocina_form_return_url" value="<?php echo esc_attr( $return_url ); ?>">
		<input type="hidden" name="crocina_form_page_id" value="<?php echo esc_attr( $current_page_id ); ?>">
		<?php wp_nonce_field( 'crocina_form_' . $form_id, '_crocina_nonce' ); ?>
		<input type="hidden" name="_crocina_ajax_nonce" value="<?php echo esc_attr( $ajax_nonce ); ?>">
		<input type="hidden" name="crocina_form_timestamp" value="<?php echo esc_attr( time() ); ?>">
		<input type="text" name="<?php echo esc_attr( $honeypot_name ); ?>" value="" class="crocina-honeypot" autocomplete="off" tabindex="-1" aria-hidden="true">
		<div class="crocina-fields-grid">
			<?php foreach ( $fields as $index => $field ) : ?>
				<?php
			echo $this->render_field( $field, array(
				'field_index'      => $index,
			) );
				?>
			<?php endforeach; ?>
		</div>
		<?php if ( $show_footer ) : ?>
		<button type="submit" class="crocina-button<?php echo $use_primary_color ? ' crocina-btn-primary-color' : ''; ?>" data-loading-text="<?php echo esc_attr__( 'Sending...', 'crocina-forms' ); ?>" style="<?php echo esc_attr( $button_style ); ?>">
			<?php if ( ! empty( $design['button_icon'] ) ) : ?>
				<span class="dashicons <?php echo esc_attr( $design['button_icon'] ); ?>" aria-hidden="true"></span>
			<?php endif; ?>
			<span class="crocina-button__label"><?php echo esc_html( $design['button_text'] ?? '' ); ?></span>
		</button>
		<?php endif; ?>
		<?php if ( $custom_css ) : ?>
			<?php // B9/W3: esc_html() would HTML-encode CSS operators (>, &) and corrupt the styles. ?>
			<?php // wp_strip_all_tags() removes any tag (including a </style> breakout) without mangling valid CSS. ?>
			<style><?php echo wp_strip_all_tags( $custom_css ); ?></style>
		<?php endif; ?>
	</form>
</div>
