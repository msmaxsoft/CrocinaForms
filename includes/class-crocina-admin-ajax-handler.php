<?php
/**
 * Admin AJAX Handlers — all wp_ajax_ and wp_ajax_nopriv_ handlers.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

final class Crocina_Admin_Ajax_Handler {

	use Crocina_Input_Helper;

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
	 * Register AJAX hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_crocina_mark_read', array( $this, 'handle_mark_read_ajax' ) );
		add_action( 'wp_ajax_crocina_quick_view_log', array( $this, 'handle_quick_view_ajax' ) );
		add_action( 'wp_ajax_crocina_preview_form', array( $this, 'handle_preview_ajax' ) );
		add_action( 'wp_ajax_crocina_quick_edit_title', array( $this, 'handle_quick_edit_title' ) );
		add_action( 'wp_ajax_crocina_partial_export', array( $this, 'handle_partial_export' ) );
	}

	/**
	 * Handle mark-read AJAX request.
	 */
	public function handle_mark_read_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to update logs.', 'crocina-forms' ) ), 403 );
		}

		check_ajax_referer( 'crocina_mark_read', 'nonce' );

		$logger = $this->app->resolve( 'logger' );
		if ( ! $logger ) {
			wp_send_json_error( array( 'message' => __( 'Logger is unavailable.', 'crocina-forms' ) ), 500 );
		}

		$log_id = absint( $this->input_post( 'log_id' ) );
		if ( ! $log_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing log ID.', 'crocina-forms' ) ), 400 );
		}

		$updated = $logger->mark_read( $log_id );
		if ( ! $updated ) {
			wp_send_json_error( array( 'message' => __( 'Unable to update log status.', 'crocina-forms' ) ), 500 );
		}

		wp_send_json_success( array( 'log_id' => $log_id ) );
	}

	/**
	 * Handle quick-view AJAX request.
	 */
	public function handle_quick_view_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to view logs.', 'crocina-forms' ) ), 403 );
		}

		check_ajax_referer( 'crocina_quick_view_log', 'nonce' );

		$logger = $this->app->resolve( 'logger' );
		if ( ! $logger ) {
			wp_send_json_error( array( 'message' => __( 'Logger is unavailable.', 'crocina-forms' ) ), 500 );
		}

		$log_id = absint( $this->input_post( 'log_id' ) );
		if ( ! $log_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing log ID.', 'crocina-forms' ) ), 400 );
		}

		$log = $logger->get_log( $log_id );
		if ( ! $log ) {
			wp_send_json_error( array( 'message' => __( 'Log not found.', 'crocina-forms' ) ), 404 );
		}

		$payload = json_decode( $log->payload, true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		$fields_markup = '';
		if ( isset( $payload['fields'] ) && is_array( $payload['fields'] ) ) {
			foreach ( $payload['fields'] as $field ) {
				$label = '';
				$value = '';
				if ( is_array( $field ) && isset( $field['label'], $field['value'] ) ) {
					$label = sanitize_text_field( $field['label'] );
					$value = sanitize_text_field( $field['value'] );
				} elseif ( is_array( $field ) ) {
					$label = sanitize_text_field( (string) key( $field ) );
					$value = sanitize_text_field( (string) current( $field ) );
				} else {
					$value = sanitize_text_field( (string) $field );
				}
				$fields_markup .= sprintf(
					'<div class="crocina-quick-view-row"><strong>%1$s</strong><span>%2$s</span></div>',
					esc_html( $label ?: __( 'Field', 'crocina-forms' ) ),
					esc_html( $value )
				);
			}
		}

		$logger->mark_read( $log_id );

		wp_send_json_success( array(
			'log_id' => $log_id,
			'form'   => get_the_title( $log->form_id ),
			'date'   => $log->submitted_at,
			'ip'     => $log->user_ip,
			'page'   => $log->page_url,
			'fields' => $fields_markup ?: '<p>' . esc_html__( 'No data', 'crocina-forms' ) . '</p>',
		) );
	}

	/**
	 * Handle preview AJAX request.
	 */
	public function handle_preview_ajax() {
		check_ajax_referer( 'crocina_preview_form', 'nonce' );

		$form_id = absint( $this->input_post( 'crocina_form_id' ) );
		if ( $form_id ) {
			if ( ! current_user_can( 'edit_post', $form_id ) ) {
				wp_send_json_error( array( 'message' => __( 'You are not allowed to preview forms.', 'crocina-forms' ) ), 403 );
			}
		} elseif ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to preview forms.', 'crocina-forms' ) ), 403 );
		}

		$meta_boxes = $this->app->resolve( 'admin_meta_boxes' );
		$core       = $this->app->resolve( 'core' );
		$render     = $this->app->resolve( 'render' );

		$fields   = $meta_boxes->sanitize_form_fields( $this->get_post_array() );
		$design   = $meta_boxes->sanitize_design( $this->get_post_array() );
		$settings = $core->get_global_settings();
		$has_file = false;
		foreach ( $fields as $field ) {
			if ( ( $field['type'] ?? '' ) === 'file' ) {
				$has_file = true;
				break;
			}
		}

		/* Resolve preview theme from the AJAX payload. */
		$allowed_themes = array( 'modern', 'classic', 'minimal' );
		$preview_theme = sanitize_key( $this->input_post( 'crocina_preview_theme', 'modern' ) );
		if ( ! in_array( $preview_theme, $allowed_themes, true ) ) {
			$preview_theme = 'modern';
		}

		$form = (object) array(
			'ID'         => 0,
			'post_title' => __( 'Preview', 'crocina-forms' ),
		);

		/* Build CSS vars with the theme primary color if available. */
		$css_vars = $this->build_preview_css_vars( $design );

		$view_data = array(
			'form'            => $form,
			'fields'          => $fields,
			'design'          => $design,
			'settings'        => $settings,
			'status'          => '',
			'message'         => '',
			'submitted_at'    => 0,
			'button_style'    => $this->build_preview_button_style( $design ),
			'current_page_id' => 0,
			'return_url'      => '',
			'has_file'        => $has_file,
			'ajax_nonce'      => '',
			'honeypot_name'   => '',
			'form_css_vars'   => $css_vars,
			'form_css_class'  => 'crocina-theme-' . $preview_theme,
			'ajax_enabled'    => false,
		);

		$markup = $render->render( 'form', $view_data );
		$html = $this->wrap_preview_html( $markup, $preview_theme );

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Handle quick edit title AJAX request.
	 */
	public function handle_quick_edit_title() {
		check_ajax_referer( 'crocina_quick_edit_' . absint( $this->input_post( 'form_id' ) ), 'nonce' );

		if ( ! current_user_can( 'edit_post', absint( $this->input_post( 'form_id' ) ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'crocina-forms' ) ) );
		}

		$form_id = absint( $this->input_post( 'form_id' ) );
		$new_title = sanitize_text_field( wp_unslash( $this->input_post( 'title' ) ) );

		if ( ! $form_id || '' === $new_title ) {
			wp_send_json_error( array( 'message' => __( 'Invalid input.', 'crocina-forms' ) ) );
		}

		if ( 'crocina_form' !== get_post_type( $form_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid input.', 'crocina-forms' ) ) );
		}

		wp_update_post( array(
			'ID'         => $form_id,
			'post_title' => $new_title,
		) );

		wp_send_json_success( array( 'title' => $new_title ) );
	}

	/**
	 * Handle partial export AJAX request.
	 */
	public function handle_partial_export() {
		check_ajax_referer( 'crocina_partial_export' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'crocina-forms' ) ) );
		}

		$categories = array_map( 'sanitize_key', (array) ( $this->input_post( 'categories', array() ) ) );
		$categories = array_intersect( $categories, array( 'settings', 'forms', 'logs' ) );

		if ( empty( $categories ) ) {
			wp_send_json_error( array( 'message' => __( 'No categories selected.', 'crocina-forms' ) ) );
		}

		$core   = $this->app->resolve( 'core' );
		$logger = $this->app->resolve( 'logger' );
		$admin  = $this->app->resolve( 'admin' );

		$global_settings = $core->get_global_settings();
		$data = array();

		if ( in_array( 'settings', $categories, true ) ) {
			$data['settings'] = $global_settings;
		}

		if ( in_array( 'forms', $categories, true ) ) {
			$forms = get_posts( array(
				'post_type'   => 'crocina_form',
				'post_status' => array( 'publish', 'draft' ),
				'numberposts' => -1,
			) );
			$form_data = array();
			foreach ( $forms as $form ) {
				$form_data[] = array(
					'title'   => get_the_title( $form ),
					'slug'    => $form->post_name,
					'fields'  => get_post_meta( $form->ID, 'crocina_fields', true ),
					'design'  => get_post_meta( $form->ID, 'crocina_form_design', true ),
					'alerts'  => get_post_meta( $form->ID, 'crocina_alert_options', true ),
				);
			}
			$data['forms'] = $form_data;
		}

		if ( in_array( 'logs', $categories, true ) ) {
			$logs = $logger->get_logs( array(
				'per_page' => 200,
				'order'    => 'DESC',
			) );
			$slug_map = $admin->map_form_slugs( wp_list_pluck( $logs, 'form_id' ) );
			$log_entries = array();
			foreach ( $logs as $log ) {
				$log_entries[] = array(
					'form_slug'    => $slug_map[ (int) $log->form_id ] ?? '',
					'submitted_at' => $log->submitted_at,
					'user_ip'      => $log->user_ip,
					'user_agent'   => $log->user_agent ?? '',
					'page_title'   => $log->page_title ?? '',
					'page_url'     => $log->page_url,
					'fields'       => json_decode( $log->payload, true ) ?: array(),
				);
			}
			$data['logs'] = $log_entries;
		}

		$data['meta'] = array(
			'exported_at'    => current_time( 'mysql' ),
			'format_version' => '1.0',
			'plugin_version' => CROCINA_FORMS_VERSION,
			'categories'     => $categories,
		);

		wp_send_json_success( array(
			'json' => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
		) );
	}

	/**
	 * Build preview CSS variables from design settings.
	 */
	public function build_preview_css_vars( $design ) {
		$vars = array();
		if ( ! empty( $design['button_background'] ) ) {
			$vars[] = '--crocina-btn-bg: ' . $design['button_background'];
		}
		if ( ! empty( $design['button_text_color'] ) ) {
			$vars[] = '--crocina-btn-color: ' . $design['button_text_color'];
		}
		if ( ! empty( $design['field_text_color'] ) ) {
			$vars[] = '--crocina-label-color: ' . $design['field_text_color'];
		}
		if ( ! empty( $design['form_background'] ) ) {
			$vars[] = '--crocina-form-bg: ' . $design['form_background'];
		}

		return implode( '; ', array_map( 'trim', $vars ) );
	}

	/**
	 * Build preview button style string from design settings.
	 */
	public function build_preview_button_style( $design ) {
		$styles = array();
		if ( ! empty( $design['button_background'] ) ) {
			$styles[] = 'background-color: ' . esc_attr( $design['button_background'] );
		}
		if ( ! empty( $design['button_text_color'] ) ) {
			$styles[] = 'color: ' . esc_attr( $design['button_text_color'] );
		}

		return implode( '; ', $styles );
	}

	/**
	 * Wrap preview HTML in a standalone document.
	 */
	public function wrap_preview_html( $content, $theme = 'modern' ) {
		$styles = array(
			esc_url( includes_url( 'css/dashicons.min.css' ) ),
			esc_url( CROCINA_FORMS_URL . 'assets/form.css' ),
		);
		$links = '';
		foreach ( $styles as $style_url ) {
			$links .= '<link rel="stylesheet" href="' . $style_url . '">';
		}

		$theme_class = 'crocina-preview-theme-' . $theme;

		$preview_styles = '<style>'
			. '.crocina-preview-body{background:#f6f8fc;margin:0;padding:0;font-family:inherit;}'
			. '.crocina-preview-body .crocina-form{max-width:520px;margin:1rem auto;padding:0.75rem;box-shadow:none;border:1px solid #e3e8f4;}'
			. '.crocina-preview-body .crocina-form__inner{padding:0.75rem;gap:0.75rem;}'
			. '.crocina-preview-body .crocina-field label{font-size:12px;gap:0.3rem;}'
			. '.crocina-preview-body .crocina-field input,.crocina-preview-body .crocina-field textarea,.crocina-preview-body .crocina-field select{padding:0.45rem 0.55rem;font-size:12px;border-radius:6px;}'
			. '.crocina-preview-body .crocina-field-helper{font-size:11px;margin-top:0.25rem;}'
			. '.crocina-preview-body .crocina-button{padding:0.5rem 0.85rem;font-size:0.9rem;border-radius:6px;}'
			/* Theme-specific preview overrides */
			. '.crocina-preview-theme-classic .crocina-form{background:#f4f1eb;border-color:#d4c9b5;}'
			. '.crocina-preview-theme-classic .crocina-button{background:#2c3e50 !important;border-radius:0 !important;text-transform:uppercase;letter-spacing:1px;font-size:11px;}'
			. '.crocina-preview-theme-minimal .crocina-form{background:transparent;border:none;box-shadow:none;}'
			. '.crocina-preview-theme-minimal .crocina-form__inner{padding:0;}'
			. '.crocina-preview-theme-minimal .crocina-button{background:transparent !important;border:1px solid var(--crocina-btn-bg, #333) !important;color:var(--crocina-btn-bg, #333) !important;box-shadow:none;}'
			. '.crocina-preview-body.' . $theme_class . ' .crocina-form{transition:all 0.2s ease;}'
			. '</style>';

		return '<!doctype html><html><head><meta charset="utf-8">' . $links . $preview_styles . '</head><body class="crocina-preview-body ' . $theme_class . '">' . $content . '</body></html>';
	}
}
