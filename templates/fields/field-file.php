<?php
defined( 'ABSPATH' ) || exit;

$data = is_array( $data ?? null ) ? $data : array();
$field = is_array( $data['field'] ?? null ) ? $data['field'] : array();
$label = $field['label'] ?? '';
$slug  = sanitize_key( $field['slug'] ?? 'field' );
$helper_text = sanitize_text_field( $field['helper_text'] ?? '' );
$required = ! empty( $field['required'] ) ? 'required' : '';
$accept = '';
if ( ! empty( $field['accept'] ) ) {
	$accept = ' accept="' . esc_attr( $field['accept'] ) . '"';
}
$width_class = $data['width_class'] ?? 'crocina-col-1-1';
?>
<div class="crocina-field crocina-field-<?php echo esc_attr( $slug ); ?> <?php echo esc_attr( $width_class ); ?>">
	<label for="crocina-file-<?php echo esc_attr( $slug ); ?>">
		<span><?php echo wp_kses( $label, $this->get_label_allowed_tags() ); ?></span>
	</label>
	<div class="crocina-drop-zone" data-field-slug="<?php echo esc_attr( $slug ); ?>">
		<div class="crocina-drop-zone-message">
			<svg class="crocina-drop-icon" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
				<path d="M10 3L10 13M10 3L6 7M10 3L14 7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
				<path d="M2 14L2 16C2 17.1046 2.89543 18 4 18L16 18C17.1046 18 18 17.1046 18 16L18 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>
			<span><?php esc_html_e( 'Drop file here or click to select', 'crocina-forms' ); ?></span>
		</div>
		<input type="file" id="crocina-file-<?php echo esc_attr( $slug ); ?>" name="<?php echo esc_attr( $slug ); ?>"<?php echo $accept; ?> <?php echo esc_attr( $required ); ?>>
	</div>
	<div class="crocina-file-preview" aria-live="polite" aria-label="<?php esc_attr_e( 'Selected file preview', 'crocina-forms' ); ?>"></div>
	<?php if ( $helper_text ) : ?>
		<small id="crocina-helper-<?php echo esc_attr( $slug ); ?>" class="crocina-field-helper"><?php echo esc_html( $helper_text ); ?></small>
	<?php endif; ?>
</div>
