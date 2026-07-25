<?php
defined( 'ABSPATH' ) || exit;

$data = is_array( $data ?? null ) ? $data : array();
$field = is_array( $data['field'] ?? null ) ? $data['field'] : array();
$label = $field['label'] ?? '';
$slug  = sanitize_key( $field['slug'] ?? 'field' );
$placeholder = sanitize_text_field( $field['placeholder'] ?? '' );
$helper_text = sanitize_text_field( $field['helper_text'] ?? '' );
$required = ! empty( $field['required'] ) ? 'required' : '';
$options = array_filter( array_map( 'trim', explode( ',', $field['options'] ?? '' ) ) );
$width_class = $data['width_class'] ?? 'crocina-col-1-1';
?>
<div class="crocina-field crocina-field-<?php echo esc_attr( $slug ); ?> <?php echo esc_attr( $width_class ); ?>">
	<label for="crocina-input-<?php echo esc_attr( $slug ); ?>">
		<span><?php echo wp_kses( $label, $this->get_label_allowed_tags() ); ?></span>
		<select id="crocina-input-<?php echo esc_attr( $slug ); ?>" name="<?php echo esc_attr( $slug ); ?>" <?php echo esc_attr( $required ); ?> <?php echo $helper_text ? 'aria-describedby="crocina-helper-' . esc_attr( $slug ) . '"' : ''; ?>>
			<option value=""><?php echo esc_html( $placeholder ?: __( 'Select...', 'crocina-forms' ) ); ?></option>
			<?php foreach ( $options as $option ) : ?>
				<option value="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $option ); ?></option>
			<?php endforeach; ?>
		</select>
	</label>
	<?php if ( $helper_text ) : ?>
		<small id="crocina-helper-<?php echo esc_attr( $slug ); ?>" class="crocina-field-helper"><?php echo esc_html( $helper_text ); ?></small>
	<?php endif; ?>
</div>
