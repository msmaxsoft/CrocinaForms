<?php







defined( 'ABSPATH' ) || exit;







class Crocina_Forms_Core {







	/** @var bool Prevents double-processing of submissions. */



	private $submission_handled = false;



	/** @var bool Whether to enqueue frontend styles. */



	private $enqueue_frontend = false;



	/** @var array|null Cached global settings (in-memory). */



	private $settings_cache = null;







	/** @var array<int,array> Cached form designs keyed by form_id. */



	private $form_design_cache = array();







	/** @var Crocina_App */



	private $app;







	/**



	 * @param Crocina_App $app



	 */



	public function __construct( Crocina_App $app ) {



		$this->app = $app;



	}







	public function init() {



		add_action( 'init', array( $this, 'register_form_post_type' ) );



		add_action( 'wp_loaded', array( $this, 'maybe_handle_submission' ) );



		add_shortcode( 'crocina_form', array( $this, 'render_shortcode' ) );



		add_filter( 'the_posts', array( $this, 'detect_shortcode_in_posts' ), 10, 2 );



		add_filter( 'the_content', array( $this, 'detect_shortcode_in_content' ), 9 );



		add_filter( 'widget_text', array( $this, 'detect_shortcode_in_text' ), 9 );



		add_filter( 'widget_text_content', array( $this, 'detect_shortcode_in_text' ), 9 );



		add_action( 'crocina_forms_prune_logs', array( $this, 'execute_daily_prune' ) );



		$logger = $this->app->get( 'logger' );



		if ( $logger ) {



			add_action( 'crocina_forms_backfill_ft', array( $logger, 'backfill_payload_ft' ) );



		}



		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );



		add_action( 'init', array( $this, 'maybe_upgrade_logger' ) );







		add_action( 'add_option_crocina_forms_settings', array( $this, 'flush_settings_cache' ) );



		add_action( 'update_option_crocina_forms_settings', array( $this, 'flush_settings_cache' ) );



		add_action( 'delete_option_crocina_forms_settings', array( $this, 'flush_settings_cache' ) );







		// Flush form-design object cache when design meta changes.



		add_action( 'updated_post_meta', array( $this, 'on_updated_post_meta' ), 10, 4 );



		add_action( 'added_post_meta',   array( $this, 'on_updated_post_meta' ), 10, 4 );



		add_action( 'deleted_post_meta',  array( $this, 'on_updated_post_meta' ), 10, 4 );







		// Cache warmer — pre-build object-cache entries right after a form is saved



		// so the first frontend visitor doesn't hit a cold cache.



		add_action( 'save_post_crocina_form', array( $this, 'warm_cache' ), 100, 2 );



	}







	public function flush_settings_cache() {



		$this->settings_cache = null;



		wp_cache_delete( self::$settings_cache_key, self::$cache_group );



		delete_transient( $this->get_settings_transient_key() );



	}

	/**
	 * Flush every plugin cache layer at once.
	 *
	 * Clears the global settings cache and drops ALL per-form design and
	 * fields caches (both the in-memory request cache and the persistent
	 * object cache group). Used after bulk operations such as saving global
	 * settings via AJAX, where any form-scoped cache may now be stale.
	 *
	 * @return void
	 */
	public function flush_cache() {

		// Global settings cache.
		$this->flush_settings_cache();

		// Drop the entire in-memory request cache (design + fields entries).
		$this->form_design_cache = array();

		// Flush the persistent object cache group so nothing stale survives.
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::$cache_group );
		} else {
			wp_cache_flush();
		}
	}







	/**



	 * @param int $form_id



	 * @return void



	 */



	public function flush_form_design_cache( $form_id = 0 ) {



		if ( $form_id ) {



			unset( $this->form_design_cache[ $form_id ] );



			wp_cache_delete( 'crocina_form_design_' . $form_id, self::$cache_group );



			delete_transient( $this->get_design_transient_key( $form_id ) );



		}



	}







	/**



	 * Hooked into 'updated_post_meta' — flush the form-design cache when



	 * the design meta is saved.



	 *



	 * @param int    $meta_id



	 * @param int    $object_id



	 * @param string $meta_key



	 * @param mixed  $_meta_value



	 * @return void



	 */



	public function on_updated_post_meta( $meta_id, $object_id, $meta_key, $_meta_value ) {



		if ( 'crocina_form_design' === $meta_key ) {



			$this->flush_form_design_cache( $object_id );



		}



	}







	public function activate() {



		$this->register_form_post_type();



		flush_rewrite_rules();



		/** @var Crocina_Logger $logger */



		$logger = $this->app->get( 'logger' );



		$logger->install_table();



		if ( ! wp_next_scheduled( 'crocina_forms_prune_logs' ) ) {



			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'crocina_forms_prune_logs' );



		}



		if ( ! wp_next_scheduled( 'crocina_forms_backfill_ft' ) ) {



			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'crocina_forms_backfill_ft' );



		}



	}







	public function deactivate() {



		flush_rewrite_rules();



		wp_clear_scheduled_hook( 'crocina_forms_prune_logs' );



		wp_clear_scheduled_hook( 'crocina_forms_backfill_ft' );



	}







	public function maybe_upgrade_logger() {



		$logger = $this->app->get( 'logger' );



		if ( $logger ) {



			$logger->maybe_upgrade();



		}







		// Run the naming-convention migration (underscore → hyphen) once.



		// This renames `crocina_*` meta keys and option names to `crocina-*`.



		if ( class_exists( 'Crocina_Migration_Naming' ) ) {



			Crocina_Migration_Naming::run();



		}



	}







	public function register_form_post_type() {



		$labels = array(



			'name'               => __( 'Crocina Forms', 'crocina-forms' ),



			'singular_name'      => __( 'Crocina Form', 'crocina-forms' ),



			'add_new_item'       => __( 'Add New Form', 'crocina-forms' ),



			'edit_item'          => __( 'Edit Form', 'crocina-forms' ),



			'new_item'           => __( 'New Form', 'crocina-forms' ),



			'view_item'          => __( 'View Form', 'crocina-forms' ),



			'all_items'          => __( 'All Forms', 'crocina-forms' ),



			'search_items'       => __( 'Search Forms', 'crocina-forms' ),



			'not_found'          => __( 'No forms found', 'crocina-forms' ),



			'not_found_in_trash' => __( 'No forms found in trash', 'crocina-forms' ),



		);







		$args = array(



			'labels'             => $labels,



			'public'             => false,



			'show_ui'            => true,



			'show_in_menu'       => CROCINA_FORMS_MENU_SLUG,



			'capability_type'    => 'post',



			'has_archive'        => false,



			'menu_icon'          => 'dashicons-feedback',



			'supports'           => array( 'title' ),



			'show_in_rest'       => true,



		);







		register_post_type( 'crocina_form', $args );



	}







	public function render_shortcode( $atts ) {



		/* ---- Benchmark mode ---- */
		global $wpdb;
		$is_benchmark = ! empty( $_GET['crocina_benchmark'] );
		if ( $is_benchmark ) {
			$bench_start_time   = microtime( true );
			$bench_start_queries = (int) $wpdb->num_queries;
			$bench_start_memory  = memory_get_peak_usage( true );
		}



		// Prime the global-settings cache before any per-form processing, so



		// that all shortcodes on the same page share the same in-memory value.



		$this->preload_global_settings();







		$atts = shortcode_atts(



			array(



				'id'              => '',



				'slug'            => '',



				'form_css_class'  => '',



				'primary_color'   => '',



				'template'        => '',



				'show_footer'     => '',



				'theme'           => '',



			),



			$atts,



			'crocina_form'



		);







		$form = $this->resolve_form( $atts );



		if ( ! $form ) {



			return '<p>' . esc_html__( 'Form not found.', 'crocina-forms' ) . '</p>';



		}







		$fields = $this->get_form_fields( $form->ID );



		$fields   = is_array( $fields ) ? $fields : array();



		$has_file = false;







		$settings = $this->get_global_settings();



		$allowed_extensions = $this->parse_allowed_extensions( $settings['attachments_allowed_types'] ?? 'jpg,jpeg,png,pdf' );



		$accept_attr = $allowed_extensions ? '.' . implode( ',.', $allowed_extensions ) : '';







		$normalized_fields = array();



		foreach ( $fields as $field ) {



			$type = sanitize_key( $field['type'] ?? 'text' );



			if ( 'file' === $type ) {



				$has_file = true;



			}



			$field['type'] = $type;



			if ( 'file' === $type && $accept_attr ) {



				$field['accept'] = $accept_attr;



			}



			$normalized_fields[] = $field;



		}







		$status       = sanitize_text_field( wp_unslash( $_GET['crocina_status'] ?? '' ) );



		$message_raw  = rawurldecode( wp_unslash( $_GET['crocina_form_message'] ?? '' ) );



		$message      = sanitize_text_field( $message_raw );



		$submitted_at = absint( $_GET['crocina_submitted_at'] ?? 0 );







		$this->enqueue_frontend = true;







		$design   = $this->get_form_design( $form->ID );



		$current_page_id  = get_queried_object_id();



		$return_url       = $current_page_id ? get_permalink( $current_page_id ) : esc_url_raw( add_query_arg( null, null ) );







		/* Resolve template from: shortcode att > design meta > 'default'. */



		$allowed_templates = array( 'default', 'card', 'minimal', 'bordered', 'shadow' );



		$template_att      = sanitize_key( $atts['template'] );



		$template          = $template_att && in_array( $template_att, $allowed_templates, true )



			? $template_att



			: ( isset( $design['template'] ) && in_array( $design['template'], $allowed_templates, true )



				? $design['template']



				: 'default' );







		/* Resolve show_footer from: shortcode att > design meta > true. */



		if ( '' !== $atts['show_footer'] ) {



			$show_footer = ! in_array( $atts['show_footer'], array( '0', 'false', 'no' ), true );



		} else {



			$show_footer = isset( $design['show_footer'] ) ? (bool) $design['show_footer'] : true;



		}







		/* Resolve theme from: shortcode att > design meta > 'modern'. */



		$allowed_themes = array( 'modern', 'classic', 'minimal' );



		$theme_att      = sanitize_key( $atts['theme'] );



		$theme          = $theme_att && in_array( $theme_att, $allowed_themes, true )



			? $theme_att



			: ( isset( $design['theme'] ) && in_array( $design['theme'], $allowed_themes, true )



				? $design['theme']



				: 'modern' );







		/* Build form_css_class from: shortcode att + resolved theme. */



		$form_css_class  = sanitize_html_class( $atts['form_css_class'] );



		$theme_class     = 'crocina-theme-' . $theme;



		$form_css_class  = $form_css_class ? $form_css_class . ' ' . $theme_class : $theme_class;







		/* Resolve primary_color from: shortcode att > '#0e64b7'. */



		$primary_color = ! empty( $atts['primary_color'] ) ? sanitize_hex_color( $atts['primary_color'] ) : '#0e64b7';







		/* Merge existing form_css_vars with theme primary variable and auto-generated palette. */



		$existing_vars = $this->build_form_css_vars( $design );



		$theme_primary = '--crocina-theme-primary: ' . esc_attr( $primary_color );



		$palette       = $this->generate_primary_palette( $primary_color );



		$merged_vars   = $existing_vars ? $existing_vars . '; ' . $theme_primary : $theme_primary;



		if ( '' !== $palette ) {



			$merged_vars .= '; ' . $palette;



		}







		/* When use_primary_color is active, override --crocina-btn-bg to



		   reference the theme primary colour.  This ensures the CSS



		   variable chain within any theme-specific button rule resolves



		   to --crocina-theme-primary instead of the hardcoded per-form



		   button_background, regardless of selector specificity.       */



		if ( ! empty( $design['use_primary_color'] ) ) {



			$merged_vars .= '; --crocina-btn-bg: var(--crocina-theme-primary)';



		}







		$view_data = array(



			'form'            => $form,



			'fields'          => $normalized_fields,



			'design'          => $design,



			'settings'        => $settings,



			'status'          => $status,



			'message'         => $message,



			'submitted_at'    => $submitted_at,



			'button_style'    => $this->build_button_style( $design ),



			'use_primary_color'=> ! empty( $design['use_primary_color'] ),



			'current_page_id' => $current_page_id,



			'return_url'      => $return_url,



			'has_file'        => $has_file,



			'ajax_nonce'      => wp_create_nonce( 'crocina_form_ajax_' . $form->ID ),



			'honeypot_name'   => $this->get_honeypot_name( $form->ID ),



			'form_css_vars'   => $merged_vars,



			'form_css_class'  => $form_css_class,



			'show_footer'     => $show_footer,



		);







		/** @var Crocina_Render $render */



		$render = $this->app->get( 'render' );



		$html = $render->render( 'form', $view_data );







		/* Wrap in template-specific div so CSS selectors like



		 * `.crocina-form-template-{name} .crocina-form` still match. */



		if ( 'default' !== $template ) {



			$html = '<div class="crocina-form-template-' . esc_attr( $template ) . '">' . $html . '</div>';



		}







		/* ---- Benchmark footer ---- */
		if ( $is_benchmark && isset( $bench_start_time ) ) {
			$bench_end_time    = microtime( true );
			$bench_end_queries = (int) $wpdb->num_queries;
			$bench_end_memory  = memory_get_peak_usage( true );

			$bench_time_ms    = round( ( $bench_end_time - $bench_start_time ) * 1000, 1 );
			$bench_queries    = $bench_end_queries - $bench_start_queries;
			$bench_memory     = size_format( $bench_end_memory, 2 ) ?: '0 B';
			$form_id   = ! empty( $form->ID ) ? $form->ID : 0;
			$form_name = $form_id ? esc_html( get_the_title( $form ) ) : '—';
			$bench_form_label = '#' . $form_id . ' &quot;' . $form_name . '&quot;';

			$html .= '<div class="crocina-benchmark-bar" style="font-family:Menlo,Consolas,monospace;font-size:11px;line-height:1.4;padding:6px 10px;margin:4px 0;background:#1a1a2e;color:#e0e0e0;border-radius:6px;display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;">'
				. '<strong style="color:#7c9aff;">' . esc_html__( 'Benchmark', 'crocina-forms' ) . '</strong>'
				. '<span style="color:#88dba3;">' . esc_html( $bench_form_label ) . '</span>'
				. '<span title="' . esc_attr__( 'Render time', 'crocina-forms' ) . '">' . "\xe2\x8f\xb1" . ' ' . esc_html( $bench_time_ms ) . ' ms</span>'
				. '<span title="' . esc_attr__( 'Database queries', 'crocina-forms' ) . '">' . "\xf0\x9f\x97\x84" . ' ' . esc_html( $bench_queries . ( $bench_queries > 0 ? ' queries' : ' query' ) ) . '</span>'
				. '<span title="' . esc_attr__( 'Peak memory', 'crocina-forms' ) . '">' . "\xf0\x9f\x92\xbe" . ' ' . esc_html( $bench_memory ) . '</span>'
				. '</div>';
		}



		return $html;



	}







	public function detect_shortcode_in_posts( $posts, $query ) {



		if ( $this->enqueue_frontend || empty( $posts ) || ! is_array( $posts ) ) {



			return $posts;



		}



		$all_ids = array();



		foreach ( $posts as $post ) {



			if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'crocina_form' ) ) {



				$this->enqueue_frontend = true;



				$ids = $this->extract_form_ids_from_content( $post->post_content );



				$all_ids = array_merge( $all_ids, $ids );



			}



		}



		// Prime the global settings cache before per-form processing,



		// so all shortcodes on this page share one in-memory value.



		$this->preload_global_settings();







		// Batch-preload designs for ALL forms found on the page, before any



		// shortcode is rendered. This turns N individual get_post_meta() calls



		// into a single database query via update_meta_cache().



		if ( ! empty( $all_ids ) ) {



			$this->preload_form_designs( $all_ids );



		}



		return $posts;



	}







	public function detect_shortcode_in_content( $content ) {



		if ( $this->enqueue_frontend ) {



			return $content;



		}



		$this->detect_shortcode_in_text( $content );



		// Also batch-preload designs for any form shortcodes found in the content.



		$ids = $this->extract_form_ids_from_content( $content );



		if ( ! empty( $ids ) ) {
			$this->preload_form_designs( $ids );
			$this->preload_form_fields( $ids );
		}



		// Settings are already primed via the the_posts or widget_text path.



		return $content;



	}







	public function detect_shortcode_in_text( $text ) {



		if ( $this->enqueue_frontend || ! is_string( $text ) ) {



			return $text;



		}



		if ( has_shortcode( $text, 'crocina_form' ) ) {



			$this->enqueue_frontend = true;



			$ids = $this->extract_form_ids_from_content( $text );



			if ( ! empty( $ids ) ) {
			$this->preload_form_designs( $ids );
			$this->preload_form_fields( $ids );
		}



			// Preload global settings so they're cached before any shortcode



			// on a widget or custom-theme area is rendered.



			$this->preload_global_settings();



		}



		return $text;



	}







	public function maybe_handle_submission() {



		if ( $this->submission_handled ) {



			return;



		}



		if ( empty( $_POST['crocina_form_id'] ) || wp_doing_ajax() ) {



			return;



		}



		$this->process_submission( false );



	}







	public function handle_ajax_submission() {



		if ( $this->submission_handled ) {



			wp_send_json_error( array(



				'status'  => 'error',



				'message' => __( 'Unable to submit the form.', 'crocina-forms' ),



			) );



		}



		$this->process_submission( true );



	}







	private function process_submission( $is_ajax ) {



		$form_id = absint( $_POST['crocina_form_id'] ?? 0 );



		if ( ! $form_id ) {



			$this->handle_submission_result( 0, 'error', __( 'Form not found.', 'crocina-forms' ), '', 0, 0, $is_ajax );



			return;



		}







		if ( $is_ajax ) {



			$nonce = wp_unslash( $_POST['_crocina_ajax_nonce'] ?? '' );



			if ( ! wp_verify_nonce( $nonce, 'crocina_form_ajax_' . $form_id ) ) {



				$this->handle_submission_result( $form_id, 'error', __( 'Security check failed.', 'crocina-forms' ), '', 0, 0, $is_ajax );



				return;



			}



		} else {



			if ( ! isset( $_POST['_crocina_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['_crocina_nonce'] ), 'crocina_form_' . $form_id ) ) {



				$return_url     = esc_url_raw( wp_unslash( $_POST['crocina_form_return_url'] ?? '' ) );



				$return_page_id = absint( $_POST['crocina_form_page_id'] ?? 0 );



				$this->handle_submission_result( $form_id, 'error', __( 'Security check failed.', 'crocina-forms' ), $return_url, $return_page_id, 0, $is_ajax );



				return;



			}



		}







		$this->submission_handled = true;







		$return_url     = esc_url_raw( wp_unslash( $_POST['crocina_form_return_url'] ?? '' ) );



		$return_page_id = absint( $_POST['crocina_form_page_id'] ?? 0 );







		$settings = $this->get_global_settings();







		$honeypot = $this->get_honeypot_name( $form_id );



		if ( ! empty( $_POST[ $honeypot ] ?? '' ) ) {



			$this->handle_submission_result( $form_id, 'error', __( 'Spam detected.', 'crocina-forms' ), $return_url, $return_page_id, 0, $is_ajax );



			return;



		}







		$timestamp = absint( $_POST['crocina_form_timestamp'] ?? 0 );



		$min_delay = max( 1, absint( $settings['min_delay_seconds'] ?? 2 ) );



		$now       = time();



		if ( ! $timestamp || $timestamp > ( $now + 60 ) || $timestamp < ( $now - 3600 ) ) {



			$this->handle_submission_result( $form_id, 'error', __( 'Invalid timestamp.', 'crocina-forms' ), $return_url, $return_page_id, 0, $is_ajax );



			return;



		}



		if ( ( $now - $timestamp ) < $min_delay ) {



			$this->handle_submission_result( $form_id, 'error', __( 'Please wait a moment before submitting again.', 'crocina-forms' ), $return_url, $return_page_id, 0, $is_ajax );



			return;



		}







		$ip = $this->get_request_ip();



		if ( $settings['rate_limit'] ?? 0 ) {



			if ( $this->is_rate_limited( $ip, $settings ) ) {



				$this->handle_submission_result( $form_id, 'error', __( 'You are submitting too often. Try again later.', 'crocina-forms' ), $return_url, $return_page_id, 0, $is_ajax );



				return;



			}



		}







		$form = get_post( $form_id );



		if ( ! $form || 'crocina_form' !== $form->post_type ) {



			$this->handle_submission_result( $form_id, 'error', __( 'Form not found.', 'crocina-forms' ), $return_url, $return_page_id, 0, $is_ajax );



			return;



		}







		$fields = $this->get_form_fields( $form_id );
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}
		if ( count( $fields ) > 50 ) {



			$this->handle_submission_result( $form_id, 'error', __( 'Too many fields submitted.', 'crocina-forms' ), $return_url, $return_page_id, 0, $is_ajax );



			return;



		}







		$allowed_extensions = $this->parse_allowed_extensions( $settings['attachments_allowed_types'] ?? 'jpg,jpeg,png,pdf' );



		$max_attachment_mb  = max( 1, absint( $settings['attachments_max_mb'] ?? 5 ) );



		$max_attachment_size = $max_attachment_mb * 1024 * 1024;



		$attachments_enabled = ! empty( $settings['attachments_enabled'] );



		$total_attachment_size = 0;



		$attachments = array();







		require_once ABSPATH . 'wp-admin/includes/file.php';







		$values = array();



		foreach ( $fields as $index => $field ) {



			$slug = sanitize_key( $field['slug'] ?? '' );



			if ( ! $slug ) {



				$label_fallback = sanitize_key( $field['label'] ?? '' );



				$slug = $label_fallback ? $label_fallback : 'field_' . ( $index + 1 );



			}







			if ( 'file' === ( $field['type'] ?? '' ) ) {



				if ( ! $attachments_enabled ) {



					$value = '';



					$values[] = array(



						'label' => sanitize_text_field( $field['label'] ?? $slug ),



						'value' => $value,



						'slug'  => $slug,



					);



					continue;



				}







				$file = $_FILES[ $slug ] ?? null;



				if ( empty( $file ) || empty( $file['name'] ) ) {



					if ( ! empty( $field['required'] ) ) {



						$this->handle_submission_result( $form_id, 'error', __( 'File is required.', 'crocina-forms' ), $return_url, $return_page_id, 0, $is_ajax );



						return;



					}



					$value = '';



				} else {



					if ( ! empty( $file['error'] ) ) {



						$this->handle_submission_result( $form_id, 'error', __( 'File upload failed.', 'crocina-forms' ), $return_url, $return_page_id, 0, $is_ajax );



						return;



					}



					if ( ! empty( $file['size'] ) ) {



						$total_attachment_size += (int) $file['size'];



					}



					if ( ! empty( $file['size'] ) && $file['size'] > $max_attachment_size ) {



						$this->handle_submission_result( $form_id, 'error', __( 'File is too large.', 'crocina-forms' ), $return_url, $return_page_id, 0, $is_ajax );



						return;



					}



					if ( $total_attachment_size > $max_attachment_size ) {



						$this->handle_submission_result( $form_id, 'error', __( 'Total upload size is too large.', 'crocina-forms' ), $return_url, $return_page_id, 0, $is_ajax );



						return;



					}



					$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );



					if ( $allowed_extensions && ! in_array( $extension, $allowed_extensions, true ) ) {



						$this->handle_submission_result( $form_id, 'error', __( 'File type not allowed.', 'crocina-forms' ), $return_url, $return_page_id, 0, $is_ajax );



						return;



					}



					if ( ! empty( $file['tmp_name'] ) && is_uploaded_file( $file['tmp_name'] ) && function_exists( 'finfo_open' ) ) {



						$finfo = finfo_open( FILEINFO_MIME_TYPE );



						$mime  = finfo_file( $finfo, $file['tmp_name'] );



						finfo_close( $finfo );



						$allowed_mimes = array(



							'jpg'  => 'image/jpeg',



							'jpeg' => 'image/jpeg',



							'png'  => 'image/png',



							'gif'  => 'image/gif',



							'pdf'  => 'application/pdf',



							'doc'  => 'application/msword',



							'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',



						);



						$expected_mime = $allowed_mimes[ $extension ] ?? '';



						if ( $expected_mime && $mime !== $expected_mime ) {



							$this->handle_submission_result( $form_id, 'error', __( 'File type not allowed.', 'crocina-forms' ), $return_url, $return_page_id, 0, $is_ajax );



							return;



						}



					}



					$upload = wp_handle_upload( $file, array( 'test_form' => false, 'test_type' => true ) );



					if ( ! empty( $upload['error'] ) ) {



						$this->handle_submission_result( $form_id, 'error', __( 'File upload failed.', 'crocina-forms' ), $return_url, $return_page_id, 0, $is_ajax );



						return;



					}



					if ( empty( $upload['file'] ) || ! $this->validate_uploaded_file( $upload['file'], $file['name'] ?? '', $allowed_extensions ) ) {



						$this->handle_submission_result( $form_id, 'error', __( 'File upload failed.', 'crocina-forms' ), $return_url, $return_page_id, 0, $is_ajax );



						return;



					}



					// Apply watermark to image uploads if enabled.



					if ( ! empty( $settings['watermark_enabled'] ) && $upload['file'] ) {



						$watermark = new Crocina_Watermark();



						$watermark->apply( $upload['file'], $settings );



					}







					if ( $attachments_enabled ) {



						$attachments[] = array(



							'name' => basename( $upload['file'] ),



							'url'  => esc_url_raw( $upload['url'] ?? '' ),



							'path' => $upload['file'],



						);



					}



					$value = esc_url_raw( $upload['url'] ?? '' );



				}



			} else {



				$value = $this->sanitize_field_value_with_options( $slug, $field );







				$field_type  = sanitize_key( $field['type'] ?? 'text' );



				$is_required = ! empty( $field['required'] );



				$field_label = sanitize_text_field( $field['label'] ?? $slug );







				if ( $is_required && ( '' === $value || array() === $value ) ) {



					$this->handle_submission_result(



						$form_id, 'error',



						sprintf( __( 'The field "%s" is required.', 'crocina-forms' ), $field_label ),



						$return_url, $return_page_id, 0, $is_ajax



					);



					return;



				}







				if ( '' !== $value && ! $this->validate_field_value( $field_type, $value ) ) {



					$this->handle_submission_result(



						$form_id, 'error',



						sprintf( __( 'The field "%s" has an invalid value.', 'crocina-forms' ), $field_label ),



						$return_url, $return_page_id, 0, $is_ajax



					);



					return;



				}



			}



			$values[] = array(



				'label' => sanitize_text_field( $field['label'] ?? $slug ),



				'value' => $value,



				'slug'  => $slug,



			);



		}







		if ( empty( $values ) ) {



			$values = $this->build_fallback_values( $form_id );



		}







		if ( empty( $values ) ) {



			$this->handle_submission_result( $form_id, 'error', __( 'Form is empty.', 'crocina-forms' ), $return_url, $return_page_id, 0, $is_ajax );



			return;



		}







		$page_title = $return_page_id ? get_the_title( $return_page_id ) : get_the_title();



		$page_link  = $return_page_id ? get_permalink( $return_page_id ) : get_permalink();



		if ( ! $page_link && $return_url ) {



			$page_link = $return_url;



		}



		if ( ! $page_title && ! $page_link ) {



			$page_title = wp_get_referer() ?: '';



			$page_link  = wp_get_referer() ?: '';



		}







		$timestamp = time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );



		$payload = array(



			'form_id'             => $form_id,



			'fields'              => $values,



			'submitted_at'        => $this->format_datetime( $timestamp ),



			'submitted_at_jalali' => $this->format_jalali_display( $timestamp, $settings['jalali_date_format'] ?? 'short' ),



			'user_ip'             => $ip,



			'page_title'          => $page_title,



			'page_url'            => $page_link,



			'attachments'         => $attachments,



		);







		$payload = apply_filters( 'crocina_form_payload_before_dispatch', $payload, $form_id );







		/** @var Crocina_Notifications $notifications */



		$notifications = $this->app->get( 'notifications' );



		/** @var Crocina_Logger $logger */



		$logger = $this->app->get( 'logger' );







		if ( ! $notifications || ! $logger ) {



			$this->handle_submission_result( $form_id, 'error', __( 'System error.', 'crocina-forms' ), $return_url, $return_page_id, 0, $is_ajax );



			return;



		}







		$notifications->dispatch_async( $form_id, $payload );







		if ( $this->is_logging_enabled( $form_id ) ) {



			$logger->log( $form_id, $payload );



		}







		$this->dispatch( 'crocina_form_submitted', array(



			'form_id' => $form_id,



			'payload' => $payload,



		) );



		$this->dispatch( 'crocina_form_after_submission', array(



			'form_id' => $form_id,



			'payload' => $payload,



			'values'  => $values,



		) );







		$this->handle_submission_result( $form_id, 'sent', __( 'Message sent successfully.', 'crocina-forms' ), $return_url, $return_page_id, $timestamp, $is_ajax );



	}







	private function handle_submission_result( $form_id, $status, $message, $return_url, $return_page_id, $submitted_at, $is_ajax ) {



		if ( $is_ajax ) {



			$settings = $this->get_global_settings();



			$format   = $settings['jalali_date_format'] ?? 'short';



			$jalali   = $submitted_at ? $this->format_jalali_display( $submitted_at, $format ) : '';



			$response = array(



				'status'              => $status,



				'message'             => $message,



				'form_id'             => $form_id,



				'submitted_at'        => $submitted_at,



				'submitted_at_jalali' => $jalali,



			);



			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && 'error' === $status ) {



				$response['debug'] = array(



					'timestamp' => gmdate( 'Y-m-d H:i:s' ),



					'ip'        => $this->get_request_ip(),



				);



			}



			if ( 'sent' === $status ) {



				wp_send_json_success( $response );



			}



			wp_send_json_error( $response );



		}



		$this->redirect_with_message( $form_id, $status, $message, $return_url, $return_page_id, $submitted_at );



	}







	private function validate_field_value( $type, $value ) {



		if ( is_array( $value ) ) {



			$value = implode( ', ', $value );



		}



		$value = (string) $value;



		if ( '' === $value ) {



			return true;



		}



		switch ( $type ) {



			case 'email':  return (bool) is_email( $value );



			case 'url':    return false !== filter_var( $value, FILTER_VALIDATE_URL );



			case 'number': return is_numeric( $value );



			case 'tel':    return (bool) preg_match( '/^[0-9+\-\s().]{3,30}$/', $value );



			default:       return true;



		}



	}







	private function sanitize_field_value_with_options( $slug, $field ) {



		$type = sanitize_key( $field['type'] ?? 'text' );



		$options = array();



		if ( ! empty( $field['options'] ) ) {



			$options = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', $field['options'] ) ) ) );



		}







		if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) && $options ) {



			$value = $_POST[ $slug ] ?? '';



			if ( 'checkbox' === $type ) {



				$value = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : array();



				$value = array_values( array_intersect( $value, $options ) );



				return implode( ', ', $value );



			}



			$value = sanitize_text_field( wp_unslash( $value ) );



			return in_array( $value, $options, true ) ? $value : '';



		}







		$value = $_POST[ $slug ] ?? '';



		if ( is_array( $value ) ) {



			$value = implode( ', ', array_map( 'sanitize_text_field', $value ) );



		} elseif ( 'textarea' === $type ) {



			$allowed_tags = array(



				'b'      => array(), 'i' => array(), 'strong' => array(), 'em' => array(),



				'u'      => array(), 'p' => array(), 'br' => array(),



				'a'      => array( 'href' => true, 'title' => true, 'rel' => true, 'target' => true ),



				'ul'     => array(), 'ol' => array(), 'li' => array(),



			);



			$value = wp_kses( wp_unslash( $value ), $allowed_tags );



		} else {



			$value = sanitize_text_field( wp_unslash( $value ) );



		}



		return $value;



	}







	private function build_fallback_values( $form_id ) {



		$honeypot = $this->get_honeypot_name( $form_id );



		$ignored  = array(



			'action', 'crocina_form_id', 'crocina_form_return_url', 'crocina_form_page_id',



			'crocina_form_timestamp', '_crocina_nonce', '_crocina_ajax_nonce', $honeypot,



		);



		$values = array();



		foreach ( (array) $_POST as $key => $value ) {



			$key = sanitize_key( $key );



			if ( ! $key || in_array( $key, $ignored, true ) ) {



				continue;



			}



			if ( 0 === strpos( $key, 'crocina_' ) ) {



				continue;



			}



			if ( is_array( $value ) ) {



				$value = implode( ', ', array_map( 'sanitize_text_field', $value ) );



			} else {



				$value = sanitize_text_field( wp_unslash( $value ) );



			}



			if ( '' === $value ) {



				continue;



			}



			$values[] = array(



				'label' => sanitize_text_field( str_replace( '_', ' ', $key ) ),



				'value' => $value,



				'slug'  => $key,



			);



		}



		return $values;



	}







	private function parse_allowed_extensions( $list ) {



		$parts = array_filter( array_map( 'trim', explode( ',', strtolower( (string) $list ) ) ) );



		return array_values( array_unique( $parts ) );



	}







	private function validate_uploaded_file( $file_path, $original_name, $allowed_extensions ) {



		if ( ! $file_path || ! is_file( $file_path ) ) {



			return false;



		}



		$uploads = wp_get_upload_dir();



		$base_dir = wp_normalize_path( $uploads['basedir'] ?? '' );



		$real_path = wp_normalize_path( realpath( $file_path ) ?: '' );



		if ( $base_dir && $real_path && 0 !== strpos( $real_path, $base_dir ) ) {



			return false;



		}



		$check = wp_check_filetype_and_ext( $file_path, $original_name );



		$ext = strtolower( $check['ext'] ?? '' );



		if ( $allowed_extensions && ! in_array( $ext, $allowed_extensions, true ) ) {



			return false;



		}



		return true;



	}







	private function resolve_form( $atts ) {



		if ( ! empty( $atts['id'] ) ) {



			return get_post( absint( $atts['id'] ) );



		}



		if ( ! empty( $atts['slug'] ) ) {



			$slug = sanitize_title( $atts['slug'] );



			$found = get_posts( array(



				'name'           => $slug,



				'post_type'      => 'crocina_form',



				'post_status'    => 'publish',



				'posts_per_page' => 1,



				'no_found_rows'  => true,



			) );



			return ! empty( $found ) ? $found[0] : null;



		}



		return null;



	}







	private function get_request_ip() {



		$ip = $_SERVER['REMOTE_ADDR'] ?? '';



		$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';



		return apply_filters( 'crocina_client_ip', $ip );



	}







	private function is_rate_limited( $ip, $settings ) {



		$limit  = max( 1, absint( $settings['rate_limit'] ?? 5 ) );



		$window = max( 60, absint( $settings['rate_limit_window'] ?? 300 ) );



		if ( ! $ip ) {



			return false;



		}



		$key    = 'crocina_rate_' . md5( $ip );



		$count  = (int) get_transient( $key );



		if ( $count >= $limit ) {



			return true;



		}



		set_transient( $key, $count + 1, $window );



		return false;



	}







	private function redirect_with_message( $form_id, $status, $message, $return_url = '', $return_page_id = 0, $submitted_at = 0 ) {



		$redirect = $this->build_redirect_target( $return_url, $return_page_id );



		$redirect = remove_query_arg( array( 'crocina_status', 'crocina_form_message', 'crocina_form_id', 'crocina_submitted_at' ), $redirect );



		$args     = array(



			'crocina_status'       => $status,



			'crocina_form_message' => rawurlencode( $message ),



			'crocina_form_id'      => $form_id,



		);



		if ( $submitted_at ) {



			$args['crocina_submitted_at'] = absint( $submitted_at );



		}



		$args = apply_filters( 'crocina_redirect_query_args', $args, $form_id, $status );



		$url  = add_query_arg( $args, $redirect );



		$url .= '#crocina-form-' . $form_id;



		wp_safe_redirect( esc_url_raw( $url ) );



		exit;



	}







	public function format_datetime( $timestamp ) {



		return gmdate( 'Y-m-d H:i:s', $timestamp );



	}







	public function format_jalali_datetime( $timestamp ) {



		if ( ! $timestamp ) {



			return '';



		}



		$gy = gmdate( 'Y', $timestamp );



		$gm = gmdate( 'n', $timestamp );



		$gd = gmdate( 'j', $timestamp );



		$time = gmdate( 'H:i:s', $timestamp );



		list( $jy, $jm, $jd ) = $this->gregorian_to_jalali( $gy, $gm, $gd );



		return sprintf( '%04d/%02d/%02d %s', $jy, $jm, $jd, $time );



	}







	public function format_jalali_display( $timestamp, $mode = 'full' ) {



		$full = $this->format_jalali_datetime( $timestamp );



		if ( 'short' === $mode ) {



			$date = explode( ' ', $full )[0] ?? $full;



			if ( function_exists( 'mb_substr' ) ) {



				return mb_strlen( $date ) > 5 ? mb_substr( $date, 5 ) : $date;



			}



			return strlen( $date ) > 5 ? substr( $date, 5 ) : $date;



		}



		return $full;



	}







	private function gregorian_to_jalali( $gy, $gm, $gd ) {



		$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );



		$jy = ( $gy <= 1600 ) ? 0 : 979;



		$gy -= ( $gy <= 1600 ) ? 621 : 1600;



		$gy2 = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;



		$days = ( 365 * $gy ) + floor( ( $gy2 + 3 ) / 4 ) - floor( ( $gy2 + 99 ) / 100 ) + floor( ( $gy2 + 399 ) / 400 ) - 80 + $gd + $g_d_m[ $gm - 1 ];



		$jy += 33 * floor( $days / 12053 );



		$days %= 12053;



		$jy += 4 * floor( $days / 1461 );



		$days %= 1461;



		if ( $days > 365 ) {



			$jy += floor( ( $days - 1 ) / 365 );



			$days = ( $days - 1 ) % 365;



		}



		if ( $days < 186 ) {



			$jm = 1 + floor( $days / 31 );



			$jd = 1 + ( $days % 31 );



		} else {



			$jm = 7 + floor( ( $days - 186 ) / 30 );



			$jd = 1 + ( ( $days - 186 ) % 30 );



		}



		return array( $jy, $jm, $jd );



	}







	private function build_redirect_target( $return_url, $page_id ) {



		if ( $page_id ) {



			$permalink = get_permalink( $page_id );



			if ( $permalink && $this->is_safe_redirect_target( $permalink ) ) {



				return esc_url_raw( $permalink );



			}



		}



		if ( $return_url && $this->is_safe_redirect_target( $return_url ) ) {



			return esc_url_raw( $return_url );



		}



		$referer = wp_get_referer();



		if ( $referer && $this->is_safe_redirect_target( $referer ) ) {



			return esc_url_raw( $referer );



		}



		return esc_url_raw( home_url() );



	}







	/**



	 * Extract all form IDs from content that uses the crocina_form shortcode.



	 * Used by detect_shortcode_in_posts/content to preload designs in batch.



	 *



	 * @param string $content Post content or widget text.



	 * @return int[]



	 */



	private function extract_form_ids_from_content( $content ) {



		$ids = array();



		if ( ! is_string( $content ) || '' === $content ) {



			return $ids;



		}



		// Match both [crocina_form id="5"] and [crocina_form id=5].



		if ( preg_match_all( '/\[crocina_form\b[^\]]*\bid\s*=\s*["\']?(\d+)["\']?[^\]]*\]/s', $content, $matches ) ) {



			foreach ( $matches[1] as $match ) {



				$ids[] = absint( $match );



			}



		}



		return array_unique( array_filter( $ids ) );



	}







	/**



	 * Cached group key shared by both settings and design caches.



	 *



	 * @var string



	 */



	private static $cache_group = 'crocina_forms';







	/**



	 * Cache key for global settings.



	 *



	 * @var string



	 */



	private static $settings_cache_key = 'crocina_global_settings';







	/**



	 * Transient key prefix — used as a fallback when no persistent object



	 * cache is available. Transients store data in the options table (or



	 * in the object cache when one is present) and survive page requests.



	 *



	 * @var string



	 */



	private static $transient_prefix = 'crocina_fb_';  // fb = fallback







	/**



	 * TTL for transient-based fallback cache (default 1 hour).



	 *



	 * @var int



	 */



	private static $transient_ttl = 3600;







	/**



	 * Performance counters — exposed in the System Status tab for cache



	 * monitoring.  Each counter tracks how many times a code path was taken



	 * during the current page request.



	 *



	 * @var array<string,int>



	 */



	private $perf_counters = array(



		'design_preloaded'    => 0,  // Forms batch-loaded via preload_form_designs()



		'design_db_calls'     => 0,  // Individual get_post_meta() in get_form_design()



		'settings_db_calls'   => 0,  // DB reads of global settings



		'settings_cache_hits' => 0,  // In-memory, object-cache, or transient hits



		'fields_cache_hits'   => 0,  // Cache hits for form fields



		'fields_preloaded'    => 0,  // Fields batch-loaded via preload_form_fields()



		'fields_db_calls'     => 0,  // Individual get_post_meta() calls for fields



	);







	/**



	 * Check whether a persistent object cache is available.



	 *



	 * WordPress's default object cache is non-persistent (in-memory only).



	 * When a caching backend like Redis, Memcached, or APCu is active,



	 * wp_using_ext_object_cache() returns true.



	 *



	 * In the absence of a persistent cache, we fall back to transients



	 * which persist across page loads via the options table.



	 *



	 * @return bool



	 */



	private function uses_persistent_cache() {



		return wp_using_ext_object_cache();



	}







	/**



	 * Get the current cache performance counters.



	 *



	 * @return array<string,int>



	 */



	public function get_cache_stats() {



		$this->perf_counters['cache_backend'] = $this->uses_persistent_cache() ? 'persistent' : 'default';



		return $this->perf_counters;



	}







	/**



	 * Get the transient key for global settings.



	 *



	 * @return string



	 */



	private function get_settings_transient_key() {



		return self::$transient_prefix . 'settings';



	}







	/**



	 * Get the transient key for a form design.



	 *



	 * @param int $form_id



	 * @return string



	 */



	private function get_design_transient_key( $form_id ) {



		return self::$transient_prefix . 'design_' . absint( $form_id );



	}







	public function get_global_settings() {



		if ( null !== $this->settings_cache ) {



			$this->perf_counters['settings_cache_hits']++;



			return $this->settings_cache;



		}







		// 1. Check object cache (persistent on Redis/Memcached sites).



		$cached = wp_cache_get( self::$settings_cache_key, self::$cache_group );



		if ( false !== $cached ) {



			$this->settings_cache = $cached;



			$this->perf_counters['settings_cache_hits']++;



			return $this->settings_cache;



		}







		// 2. Fallback: on sites without a persistent object cache, try



		//    the transient which survives page loads via the DB.



		if ( ! $this->uses_persistent_cache() ) {



			$transient = get_transient( $this->get_settings_transient_key() );



			if ( false !== $transient && is_array( $transient ) ) {



				$this->settings_cache = $transient;



				// Also re-populate object cache for the remainder of this request.



				wp_cache_set( self::$settings_cache_key, $transient, self::$cache_group );



				return $this->settings_cache;



			}



		}







		$defaults = array(



			'telegram_token'        => '',



			'telegram_chat'         => '',



			'telegram_endpoint'     => 'https://api.telegram.org/bot%s/sendMessage',



			'eitaa_token'           => '',



			'eitaa_chat'            => '',



			'eitaa_endpoint'        => 'https://eitaayar.ir/api/bot%s/sendMessage',



			'bale_token'            => '',



			'bale_chat'             => '',



			'bale_endpoint'         => '',



			'rubika_token'          => '',



			'rubika_chat'           => '',



			'rubika_endpoint'       => 'https://botapi.rubika.ir/v3/',



			'whatsapp_token'        => '',



			'whatsapp_chat'         => '',



			'whatsapp_endpoint'     => '',



			'admin_email'           => '',



			'rate_limit'            => 5,



			'rate_limit_window'     => 300,



			'min_delay_seconds'     => 2,



			'logging_enabled'       => 1,



			'log_retention_days'    => 30,



			'spam_honeypot_name'    => 'crocina_hp',



			'dashboard_stats_days'  => 30,



			'jalali_date_format'    => 'short',



			'auto_append_jalali'    => 1,



			'auto_append_datetime_mode' => 'jalali',



			'attachments_enabled'   => 0,



			'attachments_max_mb'    => 5,



			'attachments_allowed_types' => 'jpg,jpeg,png,pdf',



			'watermark_enabled'    => 0,



			'watermark_text'       => '',



			'watermark_position'   => 'bottom-right',



			'watermark_opacity'    => 40,



			'watermark_font_size'  => 24,



			'watermark_color'      => '#ffffff',



			'event_logging_enabled' => 0,



		);







		$this->perf_counters['settings_db_calls']++;



		$this->perf_counters['settings_db_calls']++;



		$this->settings_cache = wp_parse_args( get_option( 'crocina_forms_settings', array() ), $defaults );







		// Store in object cache — on cache-backed sites this persists.



		wp_cache_set( self::$settings_cache_key, $this->settings_cache, self::$cache_group );







		// Also store as a transient when no persistent cache is detected,



		// so the value survives beyond the current page request.



		if ( ! $this->uses_persistent_cache() ) {



			set_transient( $this->get_settings_transient_key(), $this->settings_cache, self::$transient_ttl );



		}







		return $this->settings_cache;



	}







	public function is_logging_enabled( $form_id ) {



		$options = get_post_meta( $form_id, 'crocina_alert_options', true );



		$form_setting = null;



		if ( is_array( $options ) && array_key_exists( 'enable_log', $options ) ) {



			$form_setting = (bool) $options['enable_log'];



		}



		if ( null === $form_setting ) {



			$global = $this->get_global_settings();



			return (bool) $global['logging_enabled'];



		}



		return $form_setting;



	}







	public function get_honeypot_name( $form_id = 0 ) {



		$form_id = absint( $form_id );



		if ( $form_id ) {



			$hash = md5( 'crocina_hp_' . $form_id . NONCE_SALT );



			return 'field_' . substr( $hash, 0, 8 );



		}



		$settings = $this->get_global_settings();



		return sanitize_key( $settings['spam_honeypot_name'] ?? 'crocina_hp' );



	}







	private function is_safe_redirect_target( $url ) {



		$parsed = wp_parse_url( $url );



		$home   = wp_parse_url( home_url() );



		if ( isset( $parsed['host'], $home['host'] ) ) {



			return strtolower( $parsed['host'] ) === strtolower( $home['host'] );



		}



		return ! isset( $parsed['host'] ) && ! empty( $parsed['path'] );



	}







	public function enqueue_frontend_assets() {



		if ( ! $this->enqueue_frontend ) {



			$post = get_queried_object();



			if ( $post instanceof WP_Post && has_shortcode( $post->post_content, 'crocina_form' ) ) {



				$this->enqueue_frontend = true;



			}



		}



		if ( ! $this->enqueue_frontend ) {



			return;



		}







		wp_enqueue_style( 'crocina-forms-style', CROCINA_FORMS_URL . 'assets/form.css', array(), CROCINA_FORMS_VERSION );



		wp_enqueue_script( 'crocina-forms-frontend', CROCINA_FORMS_URL . 'assets/form.js', array(), CROCINA_FORMS_VERSION, true );



		wp_localize_script( 'crocina-forms-frontend', 'CrocinaForms', array(



			'ajaxUrl' => admin_url( 'admin-ajax.php' ),



			'i18n'    => array(



				'sending'     => __( 'Sending...', 'crocina-forms' ),



				'submitError' => __( 'Unable to submit the form.', 'crocina-forms' ),



			),



		) );



	}







	private function build_form_css_vars( $design ) {



		$map = array(



			'--crocina-btn-bg'              => 'button_background',



			'--crocina-btn-color'           => 'button_text_color',



			'--crocina-label-color'         => 'field_text_color',



			'--crocina-form-bg'             => 'form_background',



			'--crocina-border-radius'       => 'border_radius',



			'--crocina-padding'             => 'padding',



			'--crocina-font-size'           => 'font_size',



			'--crocina-field-border-color'  => 'field_border_color',



			'--crocina-field-focus-color'   => 'field_focus_color',



		);



		$vars = array();



		foreach ( $map as $property => $key ) {



			if ( empty( $design[ $key ] ) ) {



				continue;



			}



			$value = $this->sanitize_css_value( $design[ $key ] );



			if ( '' === $value ) {



				continue;



			}



			$vars[] = $property . ': ' . $value;



		}



		return implode( '; ', $vars );



	}







	/**



	 * Generate a CSS custom-property palette from a hex primary colour.



	 *



	 * Produces five shades (50, 100, 200, 300, 400) that templates can use



	 * for hover backgrounds, borders, focus rings, and active states without



	 * needing JS-based colour mixing at runtime.



	 *



	 * @param string $hex Hex colour (e.g. "#0e64b7").



	 * @return string Semicolon-separated CSS variable declarations, or empty.



	 */



	private function generate_primary_palette( $hex ) {



		$hex = ltrim( $hex, '#' );



		if ( 6 !== strlen( $hex ) ) {



			return '';



		}







		$r = hexdec( substr( $hex, 0, 2 ) );



		$g = hexdec( substr( $hex, 2, 2 ) );



		$b = hexdec( substr( $hex, 4, 2 ) );







		// Convert RGB → HSL so we can tweak lightness (L) while keeping hue/saturation.



		$r_norm = $r / 255;



		$g_norm = $g / 255;



		$b_norm = $b / 255;







		$max = max( $r_norm, $g_norm, $b_norm );



		$min = min( $r_norm, $g_norm, $b_norm );



		$delta = $max - $min;







		$h = 0;



		$s = 0;



		$l = ( $max + $min ) / 2;







		if ( $delta > 0 ) {



			$s = $l > 0.5 ? $delta / ( 2 - $max - $min ) : $delta / ( $max + $min );







			if ( $max === $r_norm ) {



				$h = 60 * fmod( ( $g_norm - $b_norm ) / $delta, 6 );



			} elseif ( $max === $g_norm ) {



				$h = 60 * ( ( $b_norm - $r_norm ) / $delta + 2 );



			} else {



				$h = 60 * ( ( $r_norm - $g_norm ) / $delta + 4 );



			}



		}







		if ( $h < 0 ) {



			$h += 360;



		}







		// Lightness offsets for each shade step.



		$shades = array(



			'50'  => 0.92,   // very light — background tints



			'100' => 0.84,   // light — hover backgrounds



			'200' => 0.65,   // medium-light — borders



			'300' => 0.45,   // medium — focus rings



			'400' => 0.25,   // dark — active / pressed



		);







		$vars = array();



		foreach ( $shades as $name => $lightness ) {



			// Clamp lightness so we never go below 0.05 or above 0.95.



			$L = max( 0.05, min( 0.95, $lightness ) );



			$vars[] = sprintf(



				'--crocina-primary-%s: hsl(%d, %d%%, %d%%)',



				$name,



				round( $h ),



				round( $s * 100 ),



				round( $L * 100 )



			);



		}







		return implode( '; ', $vars );



	}







	private function sanitize_css_value( $value ) {



		$value = trim( (string) $value );



		if ( '' === $value ) {



			return '';



		}



		$value = preg_replace( '/[;{}<>"\']/', '', $value );



		$value = trim( (string) $value );



		$compact = preg_replace( '/\s+/', '', strtolower( $value ) );



		if ( false !== strpos( $compact, 'url(' ) || false !== strpos( $compact, 'expression(' ) || false !== strpos( $compact, 'javascript:' ) ) {



			return '';



		}



		return $value;



	}







	private function build_button_style( $design ) {



		$styles = array();



		// When use_primary_color is active, the background comes from the



		// CSS class `.crocina-btn-primary-color` using var(--crocina-theme-primary);



		// we skip the inline background-color to let the class apply cleanly.



		if ( empty( $design['use_primary_color'] ) && $design['button_background'] ) {



			$styles[] = 'background-color: ' . esc_attr( $design['button_background'] );



		}



		if ( $design['button_text_color'] ) {



			$styles[] = 'color: ' . esc_attr( $design['button_text_color'] );



		}



		return implode( '; ', $styles );



	}







	/**



	 * Preload global settings into the in-memory cache early, so that all



	 * shortcodes and templates on the same page share a single DB read.



	 * Safe to call multiple times — subsequent invocations are no-ops.



	 *



	 * @return void



	 */



	
	public function preload_form_fields( array $form_ids ) {



		if ( empty( $form_ids ) ) {



			return;



		}



		$form_ids = array_map( 'absint', $form_ids );



		$form_ids = array_values( array_unique( array_filter( $form_ids ) ) );



		if ( empty( $form_ids ) ) {



			return;



		}



		// Skip IDs already in memory cache.



		$miss_ids = array();



		foreach ( $form_ids as $id ) {



			if ( ! array_key_exists( 'fields_' . $id, $this->form_design_cache ) ) {



				$miss_ids[] = $id;



			}



		}



		if ( empty( $miss_ids ) ) {



			return;



		}



		// Batch-load ALL meta for ALL pending form posts in ONE query.



		update_meta_cache( 'post', $miss_ids );



		$this->perf_counters['fields_preloaded'] += count( $miss_ids );



		// Extract crocina_fields meta and populate both caches.



		foreach ( $miss_ids as $id ) {



			$fields = get_post_meta( $id, 'crocina_fields', true );



			$fields = is_array( $fields ) ? $fields : array();



			$this->form_design_cache[ 'fields_' . $id ] = $fields;



			wp_cache_set( 'crocina_fields_' . $id, $fields, self::$cache_group );



			if ( ! $this->uses_persistent_cache() ) {



				set_transient( self::$transient_prefix . 'fields_' . $id, $fields, self::$transient_ttl );



			}



		}



	}





	public function preload_global_settings() {



		$this->get_global_settings();



	}







	/**



	 * Batch-preload form designs for multiple form IDs at once using WordPress's



	 * built-in update_meta_cache(), which loads ALL meta for ALL the given posts



	 * in a single database query. Individual get_post_meta() calls that follow



	 * will be served from memory instead of hitting the database again.



	 *



	 * @param int[] $form_ids Array of form post IDs.



	 * @return void



	 */



	public function preload_form_designs( array $form_ids ) {



		if ( empty( $form_ids ) ) {



			return;



		}







		$form_ids = array_map( 'absint', $form_ids );



		$form_ids = array_values( array_unique( array_filter( $form_ids ) ) );







		if ( empty( $form_ids ) ) {



			return;



		}







		// Skip IDs already in memory cache.



		$miss_ids = array();



		foreach ( $form_ids as $id ) {



			if ( ! array_key_exists( $id, $this->form_design_cache ) ) {



				$miss_ids[] = $id;



			}



		}







		if ( empty( $miss_ids ) ) {



			return;



		}







		$this->perf_counters['design_preloaded'] += count( $miss_ids );



		// Batch-load ALL meta for ALL pending form posts in ONE query.



		update_meta_cache( 'post', $miss_ids );







		// Now extract the design meta (memory hit) and populate both caches.



		$defaults = $this->get_default_design();



		foreach ( $miss_ids as $id ) {



			$meta   = get_post_meta( $id, 'crocina_form_design', true );



			$design = wp_parse_args( is_array( $meta ) ? $meta : array(), $defaults );







			$this->form_design_cache[ $id ] = $design;



			wp_cache_set( 'crocina_form_design_' . $id, $design, self::$cache_group );







			// When no persistent cache is available, also write a transient



			// so the value survives beyond the current page request.



			if ( ! $this->uses_persistent_cache() ) {



				set_transient( $this->get_design_transient_key( $id ), $design, self::$transient_ttl );



			}



		}



	}







	/**



	 * Retrieve and cache crocina_fields for a form.



	 *



	 * Uses the same 3-layer caching strategy as get_form_design():



	 * in-memory → WP Object Cache → transient fallback.



	 *



	 * @param int $form_id



	 * @return array



	 */



	public function get_form_fields( $form_id ) {



		$form_id = absint( $form_id );



		if ( ! $form_id ) {



			return array();



		}







		$cache_key = 'crocina_fields_' . $form_id;







		// 1. In-memory cache (per-request).



		if ( isset( $this->form_design_cache[ 'fields_' . $form_id ] ) ) {



			$this->perf_counters['fields_cache_hits']++;



			return $this->form_design_cache[ 'fields_' . $form_id ];



		}







		// 2. Object cache (persistent on Redis/Memcached sites).



		$cached = wp_cache_get( $cache_key, self::$cache_group );



		if ( false !== $cached && is_array( $cached ) ) {



			$this->form_design_cache[ 'fields_' . $form_id ] = $cached;



			$this->perf_counters['fields_cache_hits']++;



			return $cached;



		}







		// 3. Transient fallback (for sites without persistent object cache).



		if ( ! $this->uses_persistent_cache() ) {



			$transient = get_transient( self::$transient_prefix . 'fields_' . $form_id );



			if ( false !== $transient && is_array( $transient ) ) {



				$this->form_design_cache[ 'fields_' . $form_id ] = $transient;



				$this->perf_counters['fields_cache_hits']++;



				wp_cache_set( $cache_key, $transient, self::$cache_group );



				return $transient;



			}



		}







		// Miss — load from database.



		$this->perf_counters['fields_db_calls']++;



		$fields = get_post_meta( $form_id, 'crocina_fields', true );



		$fields = is_array( $fields ) ? $fields : array();







		// Populate all three layers.



		$this->form_design_cache[ 'fields_' . $form_id ] = $fields;



		wp_cache_set( $cache_key, $fields, self::$cache_group );







		if ( ! $this->uses_persistent_cache() ) {



			set_transient( self::$transient_prefix . 'fields_' . $form_id, $fields, self::$transient_ttl );



		}







		return $fields;



	}	public function flush_form_fields_cache( $form_id = 0 ) {



		if ( $form_id ) {



			$form_id = absint( $form_id );



			unset( $this->form_design_cache[ 'fields_' . $form_id ] );



			wp_cache_delete( 'crocina_fields_' . $form_id, self::$cache_group );



			delete_transient( self::$transient_prefix . 'fields_' . $form_id );



		}



	}






	







	public function get_form_design( $form_id ) {



		$form_id = absint( $form_id );



		if ( ! $form_id ) {



			return $this->get_default_design();



		}







		// In-memory cache — avoids repeated WP cache lookups in the same request.
		if ( array_key_exists( $form_id, $this->form_design_cache ) ) {
			return $this->form_design_cache[ $form_id ];
		}







		// Object cache (shared across requests when a persistent cache is active).



		$cache_key = 'crocina_form_design_' . $form_id;



		$cached    = wp_cache_get( $cache_key, self::$cache_group );



		if ( false !== $cached ) {



			$this->form_design_cache[ $form_id ] = $cached;



			return $cached;



		}







		// Fallback: on sites without a persistent object cache, try the transient.



		if ( ! $this->uses_persistent_cache() ) {



			$transient = get_transient( $this->get_design_transient_key( $form_id ) );



			if ( false !== $transient && is_array( $transient ) ) {



				$this->form_design_cache[ $form_id ] = $transient;



				// Also re-populate object cache for the remainder of this request.



				wp_cache_set( $cache_key, $transient, self::$cache_group );



				return $transient;



			}



		}







		$defaults = $this->get_default_design();



		$this->perf_counters['design_db_calls']++;



		$meta     = get_post_meta( $form_id, 'crocina_form_design', true );



		$design   = wp_parse_args( is_array( $meta ) ? $meta : array(), $defaults );







		$this->form_design_cache[ $form_id ] = $design;



		wp_cache_set( $cache_key, $design, self::$cache_group );







		// Also store as a transient when no persistent cache is detected.



		if ( ! $this->uses_persistent_cache() ) {



			set_transient( $this->get_design_transient_key( $form_id ), $design, self::$transient_ttl );



		}







		return $design;



	}







	/**



	 * Default form design values.



	 *



	 * @return array



	 */



	private function get_default_design() {



		return array(



			'button_text'        => __( 'Send Message', 'crocina-forms' ),



			'button_background'  => '#0e64b7',



			'button_text_color'  => '#ffffff',



			'form_background'    => '#ffffff',



			'field_text_color'   => '#343a40',



			'button_icon'        => 'dashicons-email',



			'custom_css'         => '',



			'border_radius'      => '',



			'padding'            => '',



			'font_size'          => '',



			'field_border_color' => '',



			'field_focus_color'  => '',



			'template'           => 'default',



			'show_footer'        => true,



			'theme'              => 'modern',



			'use_primary_color'  => false,



		);



	}







	public function execute_daily_prune() {



		$logger = $this->app->get( 'logger' );



		if ( ! $logger ) {



			return;



		}



		$settings = $this->get_global_settings();



		$logger->prune( absint( $settings['log_retention_days'] ?? 30 ) );



	}







	/* ------------------------------------------------------------------ */



	/*  Cache warmer                                                       */



	/* ------------------------------------------------------------------ */







	/**



	 * Pre-builds form_design and global_settings caches after a form is saved



	 * in the admin panel. This ensures the first frontend visitor after saving



	 * a form doesn't experience a cold WP Object Cache hit.



	 *



	 * On sites without a persistent cache, the getters transparently write



	 * to transients, so warm_cache() works for both scenarios.



	 *



	 * Hooked into 'save_post_crocina_form' at priority 100 so all meta is



	 * already flushed and re-saved before we re-prime the cache.



	 *



	 * @param int     $post_id ID of the saved form.



	 * @param WP_Post $post    Post object.



	 * @return void



	 */



	public function warm_cache( $post_id, $post ) {



		// Only warm published forms — skip auto-drafts, revisions, and trashed posts.



		if ( 'publish' !== get_post_status( $post ) ) {



			return;



		}



		if ( wp_is_post_revision( $post ) ) {



			return;



		}



		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {



			return;



		}







		// Calling these methods populates both the in-memory cache and the



		// persistent WP Object Cache (via wp_cache_set inside each method).



		// On non-cache-backed sites, the value is also stored as a transient



		// (with the TTL configured in self::$transient_ttl).



		// Note: get_form_design() already populates the in-memory cache entry,



		// so there is no need to call preload_form_designs() separately here.



		$this->get_global_settings();



		$this->get_form_design( $post_id );



	}







	/* ------------------------------------------------------------------ */



	/*  Event dispatching helper                                           */



	/* ------------------------------------------------------------------ */







	/**



	 * Dispatch an event via the application dispatcher.



	 * Falls back to WordPress do_action() for backward compatibility.



	 *



	 * @param string $name   Event name (e.g. 'crocina_form_submitted').



	 * @param array  $params Payload.



	 * @return void



	 */



	private function dispatch( $name, array $params = array() ) {



		$dispatcher = $this->app->get( 'events' );



		if ( $dispatcher ) {



			$dispatcher->dispatch( new Crocina_Event( $name, $params ) );



			return;



		}



		do_action( $name, ...array_values( $params ) );



	}



}



