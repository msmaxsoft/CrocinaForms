<?php

defined( 'ABSPATH' ) || exit;

class Crocina_Render {

	/**
	 * Register hooks — none needed; Render is called imperatively.
	 *
	 * @return void
	 */
	public function init() {
	}

	/**
	 * @return array
	 */
	public function get_label_allowed_tags() {
		return array(
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'span'   => array( 'class' => true ),
			'a'      => array( 'href' => true, 'target' => true, 'rel' => true ),
		);
	}

	/**
	 * @param string $template
	 * @param array  $data
	 * @return string
	 */
	public function render( $template, $data = array() ) {
		$path = $this->locate_template( $template );
		if ( ! $path ) {
			return '';
		}
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		ob_start();
		include $path;
		return ob_get_clean();
	}

	/**
	 * @param array $field
	 * @param array $context
	 * @return string
	 */
	public function render_field( $field, $context = array() ) {
		$type = sanitize_key( $field['type'] ?? 'text' );
		$width = sanitize_key( $field['column_width'] ?? '1-1' );
		$allowed_widths = array( '1-1', '1-2', '1-3' );
		if ( ! in_array( $width, $allowed_widths, true ) ) {
			$width = '1-1';
		}
		$width_class = 'crocina-col-' . $width;
		$label = $field['label'] ?? '';
		$index = isset( $context['field_index'] ) ? absint( $context['field_index'] ) : 0;
		$slug = sanitize_key( $field['slug'] ?? '' );
		if ( ! $slug && $label ) {
			$slug = sanitize_key( $label );
		}
		if ( ! $slug ) {
			$slug = 'field_' . ( $index + 1 );
		}
		$field['slug'] = $slug;
		$template = 'fields/field-' . $type;
		if ( ! $this->locate_template( $template ) ) {
			$template = 'fields/field';
		}

		$data = array_merge(
			array( 'field' => $field, 'width_class' => $width_class ),
			is_array( $context ) ? $context : array()
		);

		return $this->render( $template, $data );
	}

	/**
	 * @param string $template
	 * @return string
	 */
	public function locate_template( $template ) {
		$template = ltrim( (string) $template, '/' );
		if ( '' === $template ) {
			return '';
		}
		if ( 0 !== validate_file( $template ) ) {
			return '';
		}
		if ( false === strpos( $template, '.php' ) ) {
			$template .= '.php';
		}

		$paths = array(
			get_stylesheet_directory() . '/crocina-forms/' . $template,
			get_template_directory() . '/crocina-forms/' . $template,
			CROCINA_FORMS_DIR . 'templates/' . $template,
		);
		$paths = apply_filters( 'crocina_template_path', $paths, $template );

		foreach ( $paths as $path ) {
			if ( $path && file_exists( $path ) ) {
				return $path;
			}
		}
		return '';
	}
}
