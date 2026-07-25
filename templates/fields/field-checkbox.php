<?php
defined( 'ABSPATH' ) || exit;

$data = is_array( $data ?? null ) ? $data : array();
$field = is_array( $data['field'] ?? null ) ? $data['field'] : array();
$label = $field['label'] ?? '';
$slug  = sanitize_key( $field['slug'] ?? 'field' );
$helper_text = sanitize_text_field( $field['helper_text'] ?? '' );
$required = ! empty( $field['required'] ) ? 'required' : '';
$options = array_filter( array_map( 'trim', explode( ',', $field['options'] ?? '' ) ) );
$width_class = $data['width_class'] ?? 'crocina-col-1-1';
?>
<div class="crocina-field crocina-field-<?php echo esc_attr( $slug ); ?> <?php echo esc_attr( $width_class ); ?>">
	<label>
		<span><?php echo wp_kses( $label, $this->get_label_allowed_tags() ); ?></span>
	</label>
	<div class="crocina-field-choices">
		<?php foreach ( $options as $option ) : ?>
			<?php $id = $slug . '-' . sanitize_title( $option ); ?>
			<label class="crocina-choice" for="<?php echo esc_attr( $id ); ?>">
				<input id="<?php echo esc_attr( $id ); ?>" type="checkbox" name="<?php echo esc_attr( $slug ); ?>[]" value="<?php echo esc_attr( $option ); ?>" <?php echo esc_attr( $required ); ?>>
				<span><?php echo esc_html( $option ); ?></span>
			</label>
		<?php endforeach; ?>
	</div>
	<?php if ( $helper_text ) : ?>
		<small id="crocina-helper-<?php echo esc_attr( $slug ); ?>" class="crocina-field-helper"><?php echo esc_html( $helper_text ); ?></small>
	<?php endif; ?>
</div>
