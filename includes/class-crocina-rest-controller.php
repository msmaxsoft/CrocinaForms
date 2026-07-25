<?php
/**
 * REST API controller — provides endpoints consumed by the Gutenberg block.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

final class Crocina_Rest_Controller {

	/**
	 * Application container.
	 *
	 * @var Crocina_App
	 */
	private $app;

	/**
	 * @param Crocina_App $app
	 */
	public function __construct( Crocina_App $app ) {
		$this->app = $app;
	}

	/**
	 * Register REST routes.
	 *
	 * Hooked to 'rest_api_init'.
	 *
	 * @return void
	 */
	public function init() {
		register_rest_route( 'crocina/v1', '/forms', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_forms' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'include_fields' => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'search' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
		) );

		register_rest_route( 'crocina/v1', '/forms/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_single_form' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'id' => array(
					'type'              => 'integer',
					'required'          => true,
					'validate_callback' => function( $param ) {
						return is_numeric( $param ) && absint( $param ) > 0;
					},
				),
			),
		) );

		register_rest_route( 'crocina/v1', '/forms/(?P<id>\d+)/fields', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_form_fields' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'args'                => array(
				'id' => array(
					'type'              => 'integer',
					'required'          => true,
					'validate_callback' => function( $param ) {
						return is_numeric( $param ) && absint( $param ) > 0;
					},
				),
			),
		) );

		register_rest_route( 'crocina/v1', '/forms/(?P<id>\d+)/reorder-fields', array(
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => array( $this, 'reorder_fields' ),
			'permission_callback' => array( $this, 'check_edit_permission' ),
			'args'                => array(
				'id' => array(
					'type'              => 'integer',
					'required'          => true,
					'validate_callback' => function( $param ) {
						return is_numeric( $param ) && absint( $param ) > 0;
					},
				),
				'fields' => array(
					'type'              => 'array',
					'required'          => true,
					'validate_callback' => function( $param ) {
						return is_array( $param ) && count( $param ) <= 50;
					},
				),
			),
		) );
	}

	/**
	 * Permission check — only users who can edit posts may access.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to list forms.', 'crocina-forms' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Permission check for editing forms — requires edit_posts cap.
	 *
	 * Separate from check_permission() for endpoints that modify data.
	 *
	 * @return bool|WP_Error
	 */
	public function check_edit_permission() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to modify forms.', 'crocina-forms' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET /crocina/v1/forms
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function get_forms( $request ) {
		$include_fields = (bool) $request->get_param( 'include_fields' );
		$search         = sanitize_text_field( $request->get_param( 'search' ) );

		$args = array(
			'post_type'      => 'crocina_form',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 200,
			'no_found_rows'  => true,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( $search ) {
			$args['s'] = $search;
		}

		$forms = get_posts( $args );

		$data = array();
		foreach ( $forms as $form ) {
			$item = array(
				'id'         => $form->ID,
				'title'      => get_the_title( $form ),
				'slug'       => $form->post_name,
				'status'     => $form->post_status,
				'shortcode'  => '[crocina_form id="' . $form->ID . '"]',
				'fields'     => array(),
			);

			if ( $include_fields ) {
				$fields = get_post_meta( $form->ID, 'crocina_fields', true );
				if ( is_array( $fields ) ) {
					$item['fields'] = $this->sanitize_fields_for_api( $fields );
				}
			}

			$data[] = $item;
		}

		$response = new WP_REST_Response( array(
			'forms' => $data,
			'meta'  => array(
				'total' => count( $data ),
			),
		), 200 );
		$response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );
		return $response;
	}

	/**
	 * GET /crocina/v1/forms/{id}
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_single_form( $request ) {
		$form_id = absint( $request->get_param( 'id' ) );
		$form    = get_post( $form_id );

		if ( ! $form || 'crocina_form' !== $form->post_type ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Form not found.', 'crocina-forms' ),
				array( 'status' => 404 )
			);
		}

		$fields = get_post_meta( $form->ID, 'crocina_fields', true );
		$design = get_post_meta( $form->ID, 'crocina_form_design', true );

		if ( ! is_array( $design ) ) {
			$design = array();
		}

		$data = array(
			'id'        => $form->ID,
			'title'     => get_the_title( $form ),
			'slug'      => $form->post_name,
			'status'    => $form->post_status,
			'shortcode' => '[crocina_form id="' . $form->ID . '"]',
			'fields'    => is_array( $fields ) ? $this->sanitize_fields_for_api( $fields ) : array(),
			'design'    => $this->sanitize_design_for_api( $design ),
		);

		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );
		return $response;
	}

	/**
	 * GET /crocina/v1/forms/{id}/fields
	 *
	 * Returns only the fields array — no design, shortcode, or other metadata.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_form_fields( $request ) {
		$form_id = absint( $request->get_param( 'id' ) );
		$form    = get_post( $form_id );

		if ( ! $form || 'crocina_form' !== $form->post_type ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Form not found.', 'crocina-forms' ),
				array( 'status' => 404 )
			);
		}

		$fields = get_post_meta( $form->ID, 'crocina_fields', true );

		$response = new WP_REST_Response( array(
			'fields' => is_array( $fields ) ? $this->sanitize_fields_for_api( $fields ) : array(),
		), 200 );
		$response->header( 'Cache-Control', 'public, max-age=3600, must-revalidate' );
		return $response;
	}

	/**
	 * POST /crocina/v1/forms/{id}/reorder-fields
	 *
	 * Accepts a reordered fields array and persists it to post meta.
	 * The frontend already reads from crocina_fields, so the new order
	 * is reflected immediately on the next render.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function reorder_fields( $request ) {
		$form_id = absint( $request->get_param( 'id' ) );
		$form    = get_post( $form_id );

		if ( ! $form || 'crocina_form' !== $form->post_type ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Form not found.', 'crocina-forms' ),
				array( 'status' => 404 )
			);
		}

		$new_fields = $request->get_param( 'fields' );

		if ( ! is_array( $new_fields ) || count( $new_fields ) > 50 ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'Invalid fields data.', 'crocina-forms' ),
				array( 'status' => 400 )
			);
		}

		// Sanitize each field to match the stored format exactly.
		$sanitized = array();
		foreach ( $new_fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$sanitized[] = array(
				'label'       => sanitize_text_field( $field['label'] ?? '' ),
				'slug'        => sanitize_key( $field['slug'] ?? '' ),
				'type'        => sanitize_key( $field['type'] ?? 'text' ),
				'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
				'helper_text' => sanitize_text_field( $field['helper_text'] ?? '' ),
				'options'     => sanitize_text_field( $field['options'] ?? '' ),
				'required'    => ! empty( $field['required'] ),
				'column_width' => sanitize_key( $field['column_width'] ?? $field['width'] ?? '1-1' ),
				'group'       => sanitize_text_field( $field['group'] ?? '' ),
			);
		}

		if ( empty( $sanitized ) ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'No valid fields to save.', 'crocina-forms' ),
				array( 'status' => 400 )
			);
		}

		// Preserve the original field order in post meta.
		$saved = update_post_meta( $form_id, 'crocina_fields', $sanitized );

		if ( false === $saved ) {
			return new WP_Error(
				'rest_update_failed',
				__( 'Failed to save field order.', 'crocina-forms' ),
				array( 'status' => 500 )
			);
		}

		$response = new WP_REST_Response( array(
			'success'  => true,
			'form_id'  => $form_id,
			'count'    => count( $sanitized ),
			'fields'   => $this->sanitize_fields_for_api( $sanitized ),
		), 200 );
		$response->header( 'Cache-Control', 'no-cache, must-revalidate, max-age=0' );

		// Also flush the nonce cache by returning a fresh nonce.
		$response->header( 'X-Crocina-Nonce', wp_create_nonce( 'crocina_form_' . $form_id ) );

		return $response;
	}

	/**
	 * Strip internal meta from field data before sending to the API.
	 *
	 * @param array $fields
	 * @return array
	 */
	private function sanitize_fields_for_api( $fields ) {
		return array_map( function( $field ) {
			return array(
				'label'       => sanitize_text_field( $field['label'] ?? '' ),
				'slug'        => sanitize_key( $field['slug'] ?? '' ),
				'type'        => sanitize_key( $field['type'] ?? 'text' ),
				'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
				'helper_text' => sanitize_text_field( $field['helper_text'] ?? '' ),
				'options'     => sanitize_text_field( $field['options'] ?? '' ),
				'required'    => ! empty( $field['required'] ),
				'width'       => sanitize_key( $field['column_width'] ?? '1-1' ),
				'group'       => sanitize_text_field( $field['group'] ?? '' ),
			);
		}, $fields );
	}

	/**
	 * Return only safe design fields.
	 *
	 * @param array $design
	 * @return array
	 */
	private function sanitize_design_for_api( $design ) {
		$allowed_themes = array( 'modern', 'classic', 'minimal' );
		$theme = sanitize_key( $design['theme'] ?? '' );
		if ( ! in_array( $theme, $allowed_themes, true ) ) {
			$theme = 'modern';
		}

		$allowed_templates = array( 'default', 'card', 'minimal', 'bordered', 'shadow' );
		$template = sanitize_key( $design['template'] ?? '' );
		if ( ! in_array( $template, $allowed_templates, true ) ) {
			$template = 'default';
		}

		return array(
			'button_text'       => sanitize_text_field( $design['button_text'] ?? '' ),
			'button_icon'       => sanitize_text_field( $design['button_icon'] ?? '' ),
			'button_background' => sanitize_hex_color( $design['button_background'] ?? '' ),
			'button_text_color' => sanitize_hex_color( $design['button_text_color'] ?? '' ),
			'form_background'   => sanitize_hex_color( $design['form_background'] ?? '' ),
			'theme'             => $theme,
			'template'          => $template,
		);
	}
}
