<?php

defined( 'ABSPATH' ) || exit;

class Crocina_Admin {

	use Crocina_Input_Helper;

	private const INBOX_PER_PAGE_OPTION = 'crocina_inbox_per_page';
	private const INBOX_DEFAULT_PER_PAGE = 20;

	/**
	 * Application container.
	 *
	 * @var Crocina_App
	 */
	private $app;

	/**
	 * @var array<string, string>
	 */
	private $page_hooks = array();

	/**
	 * @var bool
	 */
	private $admin_style_enqueued = false;

	/**
	 * @var Crocina_Inbox_List_Table|null
	 */
	private $inbox_table = null;

	/**
	 * @param Crocina_App $app
	 */
	public function __construct( Crocina_App $app ) {
		$this->app = $app;
	}

	public function init() {
		// Initialize sub-classes (SRP: each owns its own hooks).
		$this->app->resolve( 'admin_meta_boxes' )->init();
		$this->app->resolve( 'admin_ajax_handler' )->init();

		// Menu & screen.
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'set-screen-option', array( $this, 'handle_screen_option' ), 10, 3 );

		// Dashboard widget.
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );

		// Classic editor button (TinyMCE).
		add_action( 'media_buttons', array( $this, 'render_editor_button' ), 15 );

		// Gutenberg block registration.
		add_action( 'init', array( $this, 'register_gutenberg_block' ) );

		// REST API for the Gutenberg block.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Form list columns, sorting, bulk actions.
		add_action( 'manage_crocina_form_posts_columns', array( $this, 'register_form_columns' ) );
		add_action( 'manage_crocina_form_posts_custom_column', array( $this, 'render_form_column' ), 10, 2 );
		add_filter( 'manage_edit-crocina_form_sortable_columns', array( $this, 'register_sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_listing_sorting' ) );
		add_filter( 'bulk_actions-edit-crocina_form', array( $this, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-crocina_form', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'maybe_notice_bulk_action' ) );

		// Admin-post handlers (resend, delete, import, dashboard bulk).
		add_action( 'admin_post_crocina_import_config', array( $this, 'handle_import' ) );
		add_action( 'admin_post_crocina_resend_log', array( $this, 'handle_resend_log' ) );
		add_action( 'admin_post_crocina_delete_log', array( $this, 'handle_delete_log' ) );
		add_action( 'admin_post_crocina_dashboard_bulk', array( $this, 'handle_dashboard_bulk' ) );
	}

	public function register_menus() {
		$page_renderer = $this->app->resolve( 'admin_page_renderer' );

		$dashboard_hook = add_menu_page(
			__( 'Crocina Forms', 'crocina-forms' ),
			__( 'Crocina Forms', 'crocina-forms' ),
			'manage_options',
			CROCINA_FORMS_MENU_SLUG,
			function() use ( $page_renderer ) { $page_renderer->render_dashboard_page(); },
			'dashicons-feedback',
			60
		);
		// Attach screen options to the dashboard menu hook.
		if ( $dashboard_hook ) {
			add_action( 'load-' . $dashboard_hook, array( $this, 'setup_dashboard_screen_options' ) );
		}

		$this->page_hooks['settings'] = add_submenu_page(
			CROCINA_FORMS_MENU_SLUG,
			__( 'Settings', 'crocina-forms' ),
			__( 'Settings', 'crocina-forms' ),
			'manage_options',
			'crocina-forms-settings',
			function() use ( $page_renderer ) { $page_renderer->render_settings_page(); }
		);

		$this->page_hooks['inbox'] = add_submenu_page(
			CROCINA_FORMS_MENU_SLUG,
			__( 'Inbox', 'crocina-forms' ),
			__( 'Inbox', 'crocina-forms' ),
			'manage_options',
			'crocina-forms-inbox',
			function() use ( $page_renderer ) { $page_renderer->render_inbox_page(); }
		);

		$this->page_hooks['export'] = add_submenu_page(
			CROCINA_FORMS_MENU_SLUG,
			__( 'Export & Import', 'crocina-forms' ),
			__( 'Export & Import', 'crocina-forms' ),
			'manage_options',
			'crocina-forms-export',
			function() use ( $page_renderer ) { $page_renderer->render_export_page(); }
		);

		$this->page_hooks['event_log'] = add_submenu_page(
			CROCINA_FORMS_MENU_SLUG,
			__( 'Event Log', 'crocina-forms' ),
			__( 'Event Log', 'crocina-forms' ),
			'manage_options',
			'crocina-forms-event-log',
			function() use ( $page_renderer ) { $page_renderer->render_event_log_page(); }
		);

		if ( ! empty( $this->page_hooks['inbox'] ) ) {
			add_action( 'load-' . $this->page_hooks['inbox'], array( $this, 'setup_inbox_screen_options' ) );
		}
	}

	public function register_settings() {
		register_setting( 'crocina_forms_settings', 'crocina_forms_settings', array(
			$this,
			'sanitize_settings',
		) );
	}

	public function enqueue_assets() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$this->register_admin_assets();

		$relevant_ids = array(
			'toplevel_page_' . CROCINA_FORMS_MENU_SLUG,
			CROCINA_FORMS_MENU_SLUG . '_page_crocina-forms-settings',
			CROCINA_FORMS_MENU_SLUG . '_page_crocina-forms-export',
			CROCINA_FORMS_MENU_SLUG . '_page_crocina-forms-inbox',
			CROCINA_FORMS_MENU_SLUG . '_page_crocina-forms-event-log',
			'dashboard',
			'index',
			'crocina_form',
			'edit-crocina_form',
		);

		if ( in_array( $screen->id, $relevant_ids, true ) || in_array( $screen->base, $relevant_ids, true ) ) {
			$this->enqueue_admin_style_once();
		}

		if ( 'crocina_form' === $screen->id || ( 'post' === $screen->base && 'crocina_form' === $screen->post_type ) ) {
			$this->enqueue_admin_style_once();
			wp_enqueue_script( 'crocina-admin-script' );
			wp_localize_script( 'crocina-admin-script', 'crocinaField', array(
				'types' => self::get_field_types(),
				'i18n'  => array(
					'newField' => __( 'New Field', 'crocina-forms' ),
					'autoKey'  => __( 'auto key', 'crocina-forms' ),
				),
			) );
		}

		if ( false !== strpos( $screen->id, 'crocina-forms-inbox' ) ) {
			$this->enqueue_admin_style_once();
			wp_enqueue_script( 'crocina-admin-script' );
		}

		if ( false !== strpos( $screen->id, 'crocina-forms-settings' ) ) {
			$this->enqueue_admin_style_once();
			wp_enqueue_script( 'crocina-admin-script' );
		}

		if ( false !== strpos( $screen->id, 'crocina-forms-export' ) ) {
			$this->enqueue_admin_style_once();
			wp_enqueue_script( 'crocina-admin-script' );
		}

		// Gutenberg block assets — enqueue on any post/page edit screen.
		if ( in_array( $screen->post_type, array( 'post', 'page' ), true ) && in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
			wp_enqueue_script( 'crocina-editor' );
			wp_localize_script( 'crocina-editor', 'CrocinaEditor', array(
				'i18n' => array(
					'no_forms' => __( 'No Crocina forms are available.', 'crocina-forms' ),
					'prompt'   => __( 'Select a Crocina form by entering its ID below:%s', 'crocina-forms' ),
					'copied'   => __( 'Shortcode inserted.', 'crocina-forms' ),
				),
			) );

			// Enqueue Gutenberg block assets.
			$this->enqueue_block_assets();
		}
	}

	public function enqueue_admin_style_once() {
		if ( $this->admin_style_enqueued ) {
			return;
		}

		wp_enqueue_style( 'crocina-admin-style' );
		wp_enqueue_style( 'list-tables' );

		$this->admin_style_enqueued = true;
	}

	private function register_admin_assets() {
		if ( ! wp_style_is( 'crocina-admin-style', 'registered' ) ) {
			wp_register_style( 'crocina-admin-style', CROCINA_FORMS_URL . 'assets/admin.css', array(), CROCINA_FORMS_VERSION );
		}

		if ( ! wp_script_is( 'crocina-admin-script', 'registered' ) ) {
			wp_register_script(
				'crocina-admin-script',
				CROCINA_FORMS_URL . 'assets/admin.js',
				array( 'jquery', 'jquery-ui-sortable', 'jquery-ui-draggable', 'jquery-ui-droppable' ),
				CROCINA_FORMS_VERSION,
				true
			);
		}

		if ( ! wp_script_is( 'crocina-editor', 'registered' ) ) {
			wp_register_script( 'crocina-editor', CROCINA_FORMS_URL . 'assets/editor.js', array( 'jquery' ), CROCINA_FORMS_VERSION, true );
		}
	}

	public function get_field_types() {
		return array(
			'text'     => __( 'Text', 'crocina-forms' ),
			'textarea' => __( 'Textarea', 'crocina-forms' ),
			'email'    => __( 'Email', 'crocina-forms' ),
			'tel'      => __( 'Telephone', 'crocina-forms' ),
			'number'   => __( 'Number', 'crocina-forms' ),
			'url'      => __( 'URL', 'crocina-forms' ),
			'file'     => __( 'File', 'crocina-forms' ),
			'select'   => __( 'Select', 'crocina-forms' ),
			'radio'    => __( 'Radio', 'crocina-forms' ),
			'checkbox' => __( 'Checkbox', 'crocina-forms' ),
		);
	}

	public function get_field_type_icon( $type ) {
		$icons = array(
			'text'     => 'dashicons-editor-textcolor',
			'textarea' => 'dashicons-editor-expand',
			'email'    => 'dashicons-email-alt',
			'tel'      => 'dashicons-phone',
			'number'   => 'dashicons-analytics',
			'url'      => 'dashicons-admin-links',
			'file'     => 'dashicons-media-default',
			'select'   => 'dashicons-arrow-down-alt2',
			'radio'    => 'dashicons-radio-button-checked',
			'checkbox' => 'dashicons-yes',
		);
		return $icons[ $type ] ?? 'dashicons-editor-textcolor';
	}

	/* ------------------------------------------------------------------ */
	/*  Form list columns, sorting, bulk actions                          */
	/* ------------------------------------------------------------------ */

	public function register_form_columns( $columns ) {
		$base = array(
			'cb'                => $columns['cb'] ?? '',
			'title'             => __( 'Form', 'crocina-forms' ),
			'crocina_shortcode' => __( 'Shortcode', 'crocina-forms' ),
		);
		$added = array(
			'crocina_created' => __( 'Created', 'crocina-forms' ),
			'crocina_logs'    => __( 'Submissions', 'crocina-forms' ),
		);

		return array_merge( $base, $added );
	}

	public function register_sortable_columns( $columns ) {
		$columns['crocina_created'] = 'crocina_created';
		return $columns;
	}

	public function apply_listing_sorting( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'crocina_form' !== $query->get( 'post_type' ) ) {
			return;
		}

		$orderby = $query->get( 'orderby' );
		if ( 'crocina_created' === $orderby ) {
			$query->set( 'orderby', 'date' );
		}
	}

	public function render_form_column( $column, $post_id ) {
		switch ( $column ) {
			case 'crocina_shortcode':
				printf( '<code>[crocina_form id="%1$d"]</code>', $post_id );
				break;
			case 'crocina_created':
				echo esc_html( get_the_date( 'Y-m-d', $post_id ) );
				break;
			case 'crocina_logs':
				$count = $this->app->resolve( 'logger' )->get_logs_count( $post_id );
				printf( '<span class="crocina-log-count">%1$d %2$s</span>', $count, esc_html__( 'entries', 'crocina-forms' ) );
				break;
		}
	}

	public function add_bulk_actions( $actions ) {
		$actions['crocina_clear_logs'] = __( 'Clear logs', 'crocina-forms' );
		return $actions;
	}

	public function handle_bulk_actions( $redirect_to, $action, $post_ids ) {
		if ( 'crocina_clear_logs' !== $action ) {
			return $redirect_to;
		}

		check_admin_referer( 'bulk-posts' );

		$logs_removed = 0;
		$logger = $this->app->resolve( 'logger' );
		foreach ( $post_ids as $post_id ) {
			$logs_removed += $logger->prune_form( $post_id );
		}

		return add_query_arg( 'crocina_logs_cleared', $logs_removed, $redirect_to );
	}

	public function maybe_notice_bulk_action() {
		if ( empty( $this->input_get( 'crocina_logs_cleared' ) ) ) {
			return;
		}

		$count = absint( $this->input_get( 'crocina_logs_cleared' ) );
		$message = esc_html( sprintf( _n( '%d log cleared.', '%d logs cleared.', $count, 'crocina-forms' ), $count ) );
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', $message );
	}

	/* ------------------------------------------------------------------ */
	/*  Admin-post handlers (resend, delete, import, dashboard bulk)      */
	/* ------------------------------------------------------------------ */

	public function handle_resend_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to resend logs.', 'crocina-forms' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'crocina_resend_log', 'crocina_resend_nonce' );

		$log_id = absint( $this->input_request( 'log_id' ) );
		$logger = $this->app->resolve( 'logger' );
		if ( ! $log_id || ! $logger ) {
			$redirect = add_query_arg(
				array(
					'page'          => 'crocina-forms-inbox',
					'crocina_resend' => 'missing',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		$log = $logger->get_log( $log_id );
		if ( ! $log ) {
			$redirect = add_query_arg(
				array(
					'page'          => 'crocina-forms-inbox',
					'crocina_resend' => 'missing',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		$payload_store = json_decode( $log->payload, true );
		$fields = array();
		$page_title = '';
		if ( is_array( $payload_store ) ) {
			if ( isset( $payload_store['fields'] ) && is_array( $payload_store['fields'] ) ) {
				$fields = $payload_store['fields'];
				$page_title = $payload_store['page_title'] ?? '';
			} else {
				$fields = $payload_store;
				$page_title = $payload_store['page_title'] ?? '';
			}
		}

		$core = $this->app->resolve( 'core' );
		$settings = $core->get_global_settings();
		$payload = array(
			'form_id'             => $log->form_id,
			'fields'              => $fields,
			'submitted_at'        => $log->submitted_at,
			'submitted_at_jalali' => $core->format_jalali_display( strtotime( $log->submitted_at ), $settings['jalali_date_format'] ?? 'short' ),
			'user_ip'             => $log->user_ip,
			'page_url'            => $log->page_url,
			'page_title'          => $page_title,
		);

		$this->app->resolve( 'notifications' )->dispatch( $log->form_id, $payload );

		$redirect = add_query_arg(
			array(
				'page'           => 'crocina-forms-inbox',
				'crocina_resend' => 'success',
				'log_id'         => $log_id,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_delete_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to delete logs.', 'crocina-forms' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'crocina_delete_log', 'crocina_delete_nonce' );

		$log_id = absint( $this->input_request( 'log_id' ) );
		$logger = $this->app->resolve( 'logger' );
		if ( ! $log_id || ! $logger ) {
			$redirect = add_query_arg(
				array(
					'page'          => 'crocina-forms-inbox',
					'crocina_delete' => 'missing',
				),
				admin_url( 'admin.php' )
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		$deleted = $logger->delete_log( $log_id );
		$status  = $deleted ? 'success' : 'failed';

		$redirect = add_query_arg(
			array(
				'page'           => 'crocina-forms-inbox',
				'crocina_delete' => $status,
				'log_id'         => $log_id,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unable to import configuration.', 'crocina-forms' ) );
		}

		check_admin_referer( 'crocina_import_config', 'crocina_import_nonce' );

		$payload = wp_unslash( $this->input_post( 'crocina_import_payload' ) );
		if ( strlen( $payload ) > 10 * 1024 * 1024 ) {
			add_settings_error( 'crocina_forms_import', 'crocina_import_failed', __( 'Import payload too large.', 'crocina-forms' ), 'error' );
			$this->redirect_after_import();
		}

		$data    = json_decode( $payload, true, 10 );
		if ( ! is_array( $data ) ) {
			add_settings_error( 'crocina_forms_import', 'crocina_import_failed', __( 'Unable to decode the provided JSON.', 'crocina-forms' ), 'error' );
			$this->redirect_after_import();
		}

		if ( ! empty( $data['settings'] ) ) {
			update_option( 'crocina_forms_settings', $this->sanitize_settings( $data['settings'] ) );
		}

		if ( ! empty( $data['forms'] ) && is_array( $data['forms'] ) ) {
			foreach ( $data['forms'] as $form ) {
				$this->import_form( $form );
			}
		}

		if ( ! empty( $data['logs'] ) && is_array( $data['logs'] ) ) {
			foreach ( $data['logs'] as $log ) {
				$this->import_log( $log );
			}
		}

		add_settings_error( 'crocina_forms_import', 'crocina_import_success', __( 'Crocina configuration imported successfully.', 'crocina-forms' ), 'updated' );
		$this->redirect_after_import();
	}

	public function handle_dashboard_bulk() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'crocina-forms' ) );
		}

		check_admin_referer( 'crocina_dashboard_bulk', 'crocina_dashboard_bulk_nonce' );

		$action = sanitize_key( $this->input_post( 'crocina_bulk_action' ) );
		$form_ids = array_map( 'absint', (array) ( $this->input_post( 'form_ids', array() ) ) );
		$form_ids = array_filter( $form_ids );

		if ( empty( $form_ids ) || ! in_array( $action, array( 'duplicate', 'delete' ), true ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . CROCINA_FORMS_MENU_SLUG ) );
			exit;
		}

		$count = 0;
		foreach ( $form_ids as $form_id ) {
			$form = get_post( $form_id );
			if ( ! $form || 'crocina_form' !== $form->post_type ) {
				continue;
			}

			if ( 'delete' === $action ) {
				wp_delete_post( $form_id, true );
				$count++;
			} elseif ( 'duplicate' === $action ) {
				$new_id = wp_insert_post( array(
					'post_type'    => 'crocina_form',
					'post_status'  => 'draft',
					'post_title'   => $form->post_title . ' (copy)',
				) );
				if ( $new_id && ! is_wp_error( $new_id ) ) {
					$meta_keys = array( 'crocina_fields', 'crocina_form_design', 'crocina_alert_options' );
					foreach ( $meta_keys as $key ) {
						$value = get_post_meta( $form_id, $key, true );
						if ( '' !== $value ) {
							update_post_meta( $new_id, $key, $value );
						}
					}
					$count++;
				}
			}
		}

		$redirect = admin_url( 'admin.php?page=' . CROCINA_FORMS_MENU_SLUG );
		$redirect = add_query_arg( 'crocina_bulk_result', $action . '_' . $count, $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/*  Editor buttons (classic & block)                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array<int, array{id:int, label:string}>
	 */
	private function get_editor_forms() {
		$forms = get_posts( array(
			'post_type'      => 'crocina_form',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 200,
			'no_found_rows'  => true,
		) );
		$payload = array();
		foreach ( $forms as $form ) {
			$payload[] = array(
				'id'    => $form->ID,
				'label' => get_the_title( $form ),
			);
		}

		return $payload;
	}

	public function render_editor_button() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
			return;
		}
		if ( ! in_array( $screen->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		$payload = wp_json_encode( $this->get_editor_forms() );
		printf(
			'<button type="button" class="button crocina-insert-form" data-forms="%1$s"><span class="dashicons dashicons-feedback"></span> %2$s</button>',
			esc_attr( $payload ),
			esc_html__( 'Add Crocina Form', 'crocina-forms' )
		);
	}

	/**
	 * Register the Gutenberg block type.
	 *
	 * Hooked to 'init'.
	 */
	public function register_gutenberg_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'crocina-block-editor',
			CROCINA_FORMS_URL . 'assets/block.js',
			array(
				'wp-blocks',
				'wp-block-editor',
				'wp-components',
				'wp-element',
				'wp-i18n',
				'wp-server-side-render',
				'wp-api-fetch',
				'wp-hooks',
			),
			CROCINA_FORMS_VERSION,
			true
		);

		wp_register_style(
			'crocina-block-editor',
			CROCINA_FORMS_URL . 'assets/block.css',
			array(),
			CROCINA_FORMS_VERSION
		);

		register_block_type( 'crocina-forms/form-selector', array(
			'editor_script'   => 'crocina-block-editor',
			'editor_style'    => 'crocina-block-editor',
			'render_callback' => array( $this, 'render_gutenberg_block' ),
			'attributes'      => array(
				'formId' => array(
					'type'    => 'number',
					'default' => 0,
				),
				'previewHeight' => array(
					'type'    => 'number',
					'default' => 440,
				),
				'showHeader' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'showFooter' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'template' => array(
					'type'    => 'string',
					'default' => 'default',
				),
				'theme' => array(
					'type'    => 'string',
					'default' => 'modern',
				),
			),
		) );
	}

	/**
	 * Enqueue Gutenberg block assets on post/page edit screens.
	 *
	 * Forms are fetched via REST API (/crocina/v1/forms) so cached pages
	 * always get fresh data without stale localized JSON.
	 *
	 * form.css is NOT enqueued here — it is only loaded on the front end
	 * when a shortcode is rendered (see enqueue_frontend_assets) or inline
	 * inside the SSR preview when a block actually renders a form.
	 */
	private function enqueue_block_assets() {
		if ( ! wp_script_is( 'crocina-block-editor', 'registered' ) ) {
			return;
		}

		wp_enqueue_script( 'crocina-block-editor' );
		wp_add_inline_script(
			'crocina-block-editor',
			'window.CrocinaBlockData = null;',
			'before'
		);
		wp_enqueue_style( 'crocina-block-editor' );
	}

	/**
	 * Register the REST API routes consumed by the Gutenberg block.
	 *
	 * Hooked to 'rest_api_init'.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		$controller = new Crocina_Rest_Controller( $this->app );
		$controller->init();
	}

	/**
	 * Return inline CSS for a given template variant.
	 *
	 * These styles are output directly in the render callback so they work
	 * on the front end (block.css is editor-only).
	 *
	 * @param string $template One of 'card', 'minimal', 'bordered', 'shadow'.
	 * @return string Empty string if template is unknown.
	 */
	private function get_template_inline_css( $template ) {
		$css_map = array(
			'card' => <<<'CSS'
.crocina-form-template-card .crocina-form {
	background: #fff;
	border-radius: 12px;
	padding: 1.5rem;
	box-shadow: 0 4px 24px rgba(0,0,0,0.08);
	border: 1px solid #e8eaed;
}
CSS
,
			'minimal' => <<<'CSS'
.crocina-form-template-minimal .crocina-form {
	background: transparent;
	padding: 0;
}
.crocina-form-template-minimal .crocina-form .crocina-fields-grid {
	gap: 0.75rem;
}
.crocina-form-template-minimal .crocina-form .crocina-button {
	border-radius: 0;
	box-shadow: none;
}
CSS
,
			'bordered' => <<<'CSS'
.crocina-form-template-bordered .crocina-form {
	background: #fff;
	border: 2px solid var(--crocina-theme-primary, #0e64b7);
	border-radius: 6px;
	padding: 1.5rem;
}
.crocina-form-template-bordered .crocina-form input,
.crocina-form-template-bordered .crocina-form textarea,
.crocina-form-template-bordered .crocina-form select {
	border-width: 2px;
	border-color: var(--crocina-theme-primary, #0e64b7);
}
CSS
,
			'shadow' => <<<'CSS'
.crocina-form-template-shadow .crocina-form {
	background: #fff;
	border-radius: 8px;
	padding: 1.5rem;
	box-shadow:
		0 1px 2px rgba(0,0,0,0.04),
		0 8px 40px rgba(0,0,0,0.08);
	border: none;
}
CSS
,
		);

		return $css_map[ $template ] ?? '';
	}

	/**
	 * Server-side render callback for the Gutenberg block.
	 *
	 * Outputs the [crocina_form] shortcode wrapped in a template-aware container.
	 * Accepts preview-only attributes (previewHeight, showHeader) which are silently
	 * ignored on the front end, and functional attributes (showFooter, template)
	 * which affect the rendered output.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_gutenberg_block( $attributes ) {
		$form_id = absint( $attributes['formId'] ?? 0 );
		if ( ! $form_id ) {
			return '';
		}

		$form = get_post( $form_id );
		if ( ! $form || 'crocina_form' !== $form->post_type ) {
			return '<p>' . esc_html__( 'Form not found.', 'crocina-forms' ) . '</p>';
		}

		/* ------------------------------------------------------------------ */
		/*  Inline form.css for the SSR preview in the block editor.          */
		/*  On the front end enqueue_frontend_assets() already loads it.      */
		/*  We inject a literal <style> tag directly into the HTML because    */
		/*  ServerSideRender returns pure HTML — wp_add_inline_style() never  */
		/*  fires during a REST SSR request.  Only loads once per request.    */
		/* ------------------------------------------------------------------ */
		static $ssr_css = null;
		if ( null === $ssr_css && is_admin() ) {
			$css_path = CROCINA_FORMS_DIR . '/assets/form.css';
			if ( is_file( $css_path ) ) {
				$ssr_css = file_get_contents( $css_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			} else {
				$ssr_css = false;
			}
		}

		$show_footer  = ! empty( $attributes['showFooter'] );
		$template     = sanitize_key( $attributes['template'] ?? 'default' );
		$theme        = sanitize_key( $attributes['theme'] ?? 'modern' );
		$primary_color = ! empty( $attributes['primaryColor'] ) ? sanitize_hex_color( $attributes['primaryColor'] ) : '#0e64b7';

		$allowed_templates = array( 'default', 'card', 'minimal', 'bordered', 'shadow' );
		if ( ! in_array( $template, $allowed_templates, true ) ) {
			$template = 'default';
		}

		/*
		 * Resolve theme from: block attribute > design meta default > 'modern'.
		 * When the block's theme is still 'modern' (the default), check if the
		 * form has a per-form default stored in its design meta.
		 */
		$allowed_themes = array( 'modern', 'classic', 'minimal' );
		if ( ! in_array( $theme, $allowed_themes, true ) ) {
			$theme = 'modern';
		}

		/*
		 * Only pass theme to the shortcode when the block explicitly set it
		 * (i.e. not 'modern').  When theme is still the block default
		 * ('modern'), omit the attribute entirely so the shortcode renderer
		 * resolves it from the form's design meta or falls back to 'modern'.
		 * This avoids double-resolution and fixes the edge case where the
		 * design meta default differs from the block default.
		 */
		$theme_att = ( 'modern' !== $theme ) ? ' theme="' . $theme . '"' : '';

		$shortcode  = '[crocina_form id="' . $form_id . '"'
			. $theme_att
			. ' template="' . $template . '"'
			. ' show_footer="' . ( $show_footer ? '1' : '0' ) . '"'
			. ' primary_color="' . $primary_color . '"]';

		$form_html = do_shortcode( $shortcode );

		/*
		 * Prepend inline form.css for the SSR preview (editor only).
		 * On the front end, enqueue_frontend_assets() loads it normally.
		 * We inject a literal <style> tag because ServerSideRender returns
		 * pure HTML — wp_add_inline_style() never fires in that context.
		 */
		$full_html = '';
		if ( is_admin() && is_string( $ssr_css ) ) {
			$full_html .= '<style>' . wp_strip_all_tags( $ssr_css ) . '</style>';
		}

		/*
		 * Collect inline template CSS for the front end.
		 * The block.css is editor-only, so template styles must be
		 * output here for the rendered form on the front end.
		 * Now uses var(--crocina-theme-primary) for color customization.
		 */
		$inline_styles = '';

		if ( 'default' !== $template ) {
			$template_css = $this->get_template_inline_css( $template );
			if ( $template_css ) {
				$inline_styles .= $template_css . "\n";
			}
		}

		if ( $inline_styles ) {
			$full_html .= '<style>' . wp_strip_all_tags( $inline_styles ) . '</style>';
		}

		$full_html .= $form_html;

		return '<div class="crocina-block-rendered">'
			. $full_html
			. '</div>';
	}

	/* ------------------------------------------------------------------ */
	/*  Settings sanitization (used as register_setting callback)          */
	/* ------------------------------------------------------------------ */

	public function sanitize_settings( $settings ) {
		$defaults = $this->app->resolve( 'core' )->get_global_settings();
		$settings = wp_parse_args( $settings, $defaults );

		return array(
			'telegram_token'     => sanitize_text_field( $settings['telegram_token'] ?? '' ),
			'telegram_chat'      => sanitize_text_field( $settings['telegram_chat'] ?? '' ),
			'telegram_endpoint'  => esc_url_raw( $settings['telegram_endpoint'] ?? '' ),
			'eitaa_token'        => sanitize_text_field( $settings['eitaa_token'] ?? '' ),
			'eitaa_chat'         => sanitize_text_field( $settings['eitaa_chat'] ?? '' ),
			'eitaa_endpoint'     => esc_url_raw( $settings['eitaa_endpoint'] ?? '' ),
			'bale_token'         => sanitize_text_field( $settings['bale_token'] ?? '' ),
			'bale_chat'          => sanitize_text_field( $settings['bale_chat'] ?? '' ),
			'bale_endpoint'      => esc_url_raw( $settings['bale_endpoint'] ?? '' ),
			'rubika_token'       => sanitize_text_field( $settings['rubika_token'] ?? '' ),
			'rubika_chat'        => sanitize_text_field( $settings['rubika_chat'] ?? '' ),
			'rubika_endpoint'    => esc_url_raw( $settings['rubika_endpoint'] ?? '' ),
			'whatsapp_token'     => sanitize_text_field( $settings['whatsapp_token'] ?? '' ),
			'whatsapp_chat'      => sanitize_text_field( $settings['whatsapp_chat'] ?? '' ),
			'whatsapp_endpoint'  => esc_url_raw( $settings['whatsapp_endpoint'] ?? '' ),
			'admin_email'        => sanitize_text_field( $settings['admin_email'] ?? '' ),
			'rate_limit'         => max( 1, absint( $settings['rate_limit'] ?? 5 ) ),
			'rate_limit_window'  => max( 60, absint( $settings['rate_limit_window'] ?? 300 ) ),
			'min_delay_seconds'  => max( 1, absint( $settings['min_delay_seconds'] ?? 2 ) ),
			'dashboard_stats_days' => max( 1, absint( $settings['dashboard_stats_days'] ?? 30 ) ),
			'jalali_date_format' => in_array( $settings['jalali_date_format'] ?? 'short', array( 'full', 'short' ), true ) ? $settings['jalali_date_format'] : 'short',
			'auto_append_jalali' => ! empty( $settings['auto_append_jalali'] ?? 0 ) ? 1 : 0,
			'auto_append_datetime_mode' => in_array( $settings['auto_append_datetime_mode'] ?? 'jalali', array( 'jalali', 'gregorian', 'both' ), true )
				? $settings['auto_append_datetime_mode']
				: 'jalali',
			'attachments_enabled' => ! empty( $settings['attachments_enabled'] ?? 0 ) ? 1 : 0,
			'attachments_max_mb' => max( 1, absint( $settings['attachments_max_mb'] ?? 5 ) ),
			'attachments_allowed_types' => sanitize_text_field( $settings['attachments_allowed_types'] ?? 'jpg,jpeg,png,pdf' ),
			'watermark_enabled'  => ! empty( $settings['watermark_enabled'] ?? 0 ) ? 1 : 0,
			'watermark_text'     => sanitize_text_field( $settings['watermark_text'] ?? '' ),
			'watermark_position' => in_array( $settings['watermark_position'] ?? 'bottom-right', array( 'bottom-right', 'bottom-left', 'top-right', 'top-left', 'center' ), true )
				? $settings['watermark_position']
				: 'bottom-right',
			'watermark_opacity'  => max( 5, min( 100, absint( $settings['watermark_opacity'] ?? 40 ) ) ),
			'watermark_font_size' => max( 8, min( 128, absint( $settings['watermark_font_size'] ?? 24 ) ) ),
			'watermark_color'    => sanitize_hex_color( $settings['watermark_color'] ?? '#ffffff' ),
			'watermark_sample_image_id' => absint( $settings['watermark_sample_image_id'] ?? 0 ),
			'event_logging_enabled' => ! empty( $settings['event_logging_enabled'] ?? 0 ) ? 1 : 0,
			'event_webhook_url'       => esc_url_raw( $settings['event_webhook_url'] ?? '' ),
			'event_webhook_rate_limit' => max( 0, absint( $settings['event_webhook_rate_limit'] ?? 60 ) ),
			'event_webhook_max_body_kb' => max( 0, absint( $settings['event_webhook_max_body_kb'] ?? 100 ) ),
			'logging_enabled'    => ! empty( $settings['logging_enabled'] ?? 0 ) ? 1 : 0,
			'log_retention_days' => max( 1, absint( $settings['log_retention_days'] ?? 30 ) ),
			'spam_honeypot_name' => sanitize_key( $settings['spam_honeypot_name'] ?? 'crocina_hp' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Screen options / inbox table helpers                               */
	/* ------------------------------------------------------------------ */

	public function setup_inbox_screen_options() {
		$option = array(
			'label'   => __( 'Inbox rows', 'crocina-forms' ),
			'default' => self::INBOX_DEFAULT_PER_PAGE,
			'option'  => self::INBOX_PER_PAGE_OPTION,
		);
		add_screen_option( 'per_page', $option );

		$form_id = absint( $this->input_get( 'form_id' ) );
		$this->ensure_inbox_table( $form_id, '' );
	}

	public function setup_dashboard_screen_options() {
		$option = array(
			'label'   => __( 'Forms per page', 'crocina-forms' ),
			'default' => 20,
			'option'  => 'crocina_forms_per_page',
		);
		add_screen_option( 'per_page', $option );
	}

	public function handle_screen_option( $status, $option, $value ) {
		if ( self::INBOX_PER_PAGE_OPTION === $option || 'crocina_forms_per_page' === $option ) {
			return $value;
		}

		return $status;
	}

	/**
	 * @return Crocina_Inbox_List_Table|null
	 */
	public function ensure_inbox_table( $form_id = 0, $search = '', $is_read = '' ) {
		if ( $this->inbox_table ) {
			return $this->inbox_table;
		}

		if ( ! class_exists( 'Crocina_Inbox_List_Table' ) ) {
			return null;
		}

		$this->inbox_table = new Crocina_Inbox_List_Table( array(
			'form_id'      => absint( $form_id ),
			'search'       => $search,
			'is_read'      => in_array( (string) $is_read, array( '0', '1' ), true ) ? (string) $is_read : '',
			'logger'       => $this->app->resolve( 'logger' ),
			'core'         => $this->app->resolve( 'core' ),
			'notifications' => $this->app->resolve( 'notifications' ),
		) );

		return $this->inbox_table;
	}

	/* ------------------------------------------------------------------ */
	/*  Utility helpers (used by PageRenderer and import/export)           */
	/* ------------------------------------------------------------------ */

	/**
	 * Verify a GET nonce.
	 */
	public function verify_get_nonce( $nonce_key, $action ) {
		if ( empty( $this->input_get( $nonce_key ) ) ) {
			return false;
		}

		return (bool) wp_verify_nonce( wp_unslash( $this->input_get( $nonce_key ) ), $action );
	}

	/**
	 * Add a safe search filter for post titles.
	 *
	 * @return \Closure The filter callback so it can be removed later.
	 */
	public function add_safe_search_filter( $search_query ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $search_query ) . '%';
		$filter = function( $where ) use ( $wpdb, $like ) {
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.post_title LIKE %s", $like );
		};
		add_filter( 'posts_where', $filter );
		return $filter;
	}

	/**
	 * Build a form_id => slug map for a set of log rows.
	 *
	 * @param int[] $form_ids
	 * @return array<int,string>
	 */
	public function map_form_slugs( $form_ids ) {
		$form_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $form_ids ) ) ) );
		if ( empty( $form_ids ) ) {
			return array();
		}

		$posts = get_posts( array(
			'post_type'        => 'crocina_form',
			'post_status'      => 'any',
			'include'          => $form_ids,
			'numberposts'      => count( $form_ids ),
			'suppress_filters' => false,
			'no_found_rows'    => true,
		) );

		$map = array();
		foreach ( $posts as $post ) {
			$map[ (int) $post->ID ] = $post->post_name;
		}

		return $map;
	}

	/**
	 * Gather all export data (forms, logs, settings, meta).
	 *
	 * @return array
	 */
	public function gather_export_data() {
		$core   = $this->app->resolve( 'core' );
		$logger = $this->app->resolve( 'logger' );
		$settings = $core->get_global_settings();
		$forms    = get_posts( array(
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
		$logs = $logger->get_logs( array(
			'per_page' => 200,
			'order'    => 'DESC',
		) );
		$slug_map = $this->map_form_slugs( wp_list_pluck( $logs, 'form_id' ) );
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
		$meta = array(
			'exported_at'     => gmdate( 'Y-m-d H:i:s' ),
			'format_version'  => '1.0',
			'plugin_version'  => CROCINA_FORMS_VERSION,
			'forms_exported'  => count( $form_data ),
			'logs_exported'   => count( $log_entries ),
			'preview'         => array(
				'first_form' => $form_data[0]['title'] ?? '',
				'recent_log' => $log_entries[0] ?? null,
			),
		);

		return array(
			'meta'     => $meta,
			'settings' => $settings,
			'forms'    => $form_data,
			'logs'     => $log_entries,
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Import helpers                                                     */
	/* ------------------------------------------------------------------ */

	private function redirect_after_import() {
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( 'settings-updated', 'true', admin_url( 'admin.php?page=crocina-forms-export' ) ) );
		exit;
	}

	private function import_form( $payload ) {
		$title  = sanitize_text_field( $payload['title'] ?? __( 'Imported Form', 'crocina-forms' ) );
		$slug   = sanitize_title( $payload['slug'] ?? $title );
		$fields = is_array( $payload['fields'] ?? null ) ? $payload['fields'] : array();
		$design = is_array( $payload['design'] ?? null ) ? $payload['design'] : array();
		$alerts = is_array( $payload['alerts'] ?? null ) ? $payload['alerts'] : array();

		$existing = $slug ? get_posts( array(
			'name'           => $slug,
			'post_type'      => 'crocina_form',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		) ) : null;
		$existing = is_array( $existing ) && ! empty( $existing ) ? $existing[0] : null;
		$post_id  = 0;
		if ( $existing ) {
			$post_id = $existing->ID;
			wp_update_post( array(
				'ID'         => $post_id,
				'post_title' => $title,
				'post_name'  => $slug,
			) );
		} else {
			$post_id = wp_insert_post( array(
				'post_title'  => $title,
				'post_name'   => $slug,
				'post_type'   => 'crocina_form',
				'post_status' => 'publish',
			) );
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return;
		}

		update_post_meta( $post_id, 'crocina_fields', $this->import_fields( $fields ) );
		update_post_meta( $post_id, 'crocina_form_design', $this->import_design( $design ) );
		update_post_meta( $post_id, 'crocina_alert_options', $this->import_alerts( $alerts ) );
	}

	private function import_fields( $fields ) {
		$result = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$width = sanitize_key( $field['column_width'] ?? '1-1' );
			if ( ! in_array( $width, array( '1-1', '1-2', '1-3' ), true ) ) {
				$width = '1-1';
			}
			$result[] = array(
				'label'       => sanitize_text_field( $field['label'] ?? '' ),
				'slug'        => sanitize_key( $field['slug'] ?? '' ),
				'type'        => sanitize_key( $field['type'] ?? 'text' ),
				'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
				'helper_text' => sanitize_text_field( $field['helper_text'] ?? '' ),
				'options'     => sanitize_text_field( $field['options'] ?? '' ),
				'required'    => ! empty( $field['required'] ) ? 1 : 0,
				'column_width'=> $width,
			);
		}
		return $result;
	}

	private function import_design( $design ) {
		return array(
			'button_text'       => sanitize_text_field( $design['button_text'] ?? '' ),
			'button_icon'       => sanitize_text_field( $design['button_icon'] ?? '' ),
			'button_background' => sanitize_hex_color( $design['button_background'] ?? '' ),
			'button_text_color' => sanitize_hex_color( $design['button_text_color'] ?? '' ),
			'form_background'   => sanitize_hex_color( $design['form_background'] ?? '' ),
			'field_text_color'  => sanitize_hex_color( $design['field_text_color'] ?? '' ),
			'custom_css'        => wp_strip_all_tags( $design['custom_css'] ?? '' ),
		);
	}

	private function import_alerts( $alerts ) {
		return array(
			'webhook_endpoints' => sanitize_textarea_field( $alerts['webhook_endpoints'] ?? '' ),
			'message_template'  => sanitize_textarea_field( $alerts['message_template'] ?? '' ),
			'email_subject'     => sanitize_text_field( $alerts['email_subject'] ?? '' ),
			'email_recipients'  => sanitize_text_field( $alerts['email_recipients'] ?? '' ),
			'enable_email'      => ! empty( $alerts['enable_email'] ) ? 1 : 0,
			'enable_telegram'   => ! empty( $alerts['enable_telegram'] ) ? 1 : 0,
			'enable_whatsapp'   => ! empty( $alerts['enable_whatsapp'] ) ? 1 : 0,
			'enable_bale'       => ! empty( $alerts['enable_bale'] ) ? 1 : 0,
			'enable_eitaa'      => ! empty( $alerts['enable_eitaa'] ) ? 1 : 0,
			'enable_rubika'     => ! empty( $alerts['enable_rubika'] ) ? 1 : 0,
			'enable_log'        => ! empty( $alerts['enable_log'] ) ? 1 : 0,
		);
	}

	private function import_log( $log ) {
		if ( empty( $log['form_slug'] ) ) {
			return;
		}
		$form = null;
		$found = get_posts( array(
			'name'           => sanitize_title( $log['form_slug'] ),
			'post_type'      => 'crocina_form',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		) );
		if ( ! empty( $found ) ) {
			$form = $found[0];
		}
		if ( ! $form ) {
			return;
		}
		$payload = array(
			'form_id'      => $form->ID,
			'submitted_at' => sanitize_text_field( $log['submitted_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
			'user_ip'      => sanitize_text_field( $log['user_ip'] ?? '' ),
			'page_url'     => esc_url_raw( $log['page_url'] ?? '' ),
			'fields'       => is_array( $log['fields'] ?? null ) ? $log['fields'] : array(),
		);
		$this->app->resolve( 'logger' )->log( $form->ID, $payload );
	}

	/* ------------------------------------------------------------------ */
	/*  Dashboard widget                                                   */
	/* ------------------------------------------------------------------ */

	public function register_dashboard_widget() {
		wp_add_dashboard_widget(
			'crocina_forms_summary',
			__( 'Crocina Form Submissions', 'crocina-forms' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	public function render_dashboard_widget() {
		$total_forms = wp_count_posts( 'crocina_form' )->publish ?? 0;
		$logger = $this->app->resolve( 'logger' );
		$core   = $this->app->resolve( 'core' );
		$total_logs  = $logger->get_logs_count();
		$settings    = $core->get_global_settings();
		$stats_days  = max( 1, absint( $settings['dashboard_stats_days'] ?? 30 ) );
		$stats       = $logger ? $logger->get_form_stats( 0, $stats_days ) : array();
		$daily_stats = $stats['daily_stats'] ?? array();
		$chart_rows  = array_slice( $daily_stats, 0, $stats_days );
		$max_count   = 0;
		foreach ( $chart_rows as $row ) {
			$max_count = max( $max_count, absint( $row->count ?? 0 ) );
		}
		?>
		<div class="crocina-dashboard-widget-wrap">
			<ul class="crocina-dashboard-widget">
				<li>
					<strong><?php echo esc_html( number_format_i18n( $total_forms ) ); ?></strong>
					<span><?php esc_html_e( 'Registered forms', 'crocina-forms' ); ?></span>
				</li>
				<li>
					<strong><?php echo esc_html( number_format_i18n( $total_logs ) ); ?></strong>
					<span><?php esc_html_e( 'Logged submissions', 'crocina-forms' ); ?></span>
				</li>
				<li>
					<strong><?php echo esc_html( number_format_i18n( absint( $stats['recent_total'] ?? 0 ) ) ); ?></strong>
					<span><?php echo esc_html( sprintf( __( 'Submissions in last %d days', 'crocina-forms' ), absint( $stats_days ) ) ); ?></span>
				</li>
			</ul>
			<?php if ( $chart_rows ) : ?>
				<div class="crocina-dashboard-chart" aria-label="<?php esc_attr_e( 'Daily submissions chart', 'crocina-forms' ); ?>">
					<?php foreach ( $chart_rows as $row ) : ?>
						<?php
						$count  = absint( $row->count ?? 0 );
						$height = $max_count ? (int) round( ( $count / $max_count ) * 100 ) : 0;
						$format = $settings['jalali_date_format'] ?? 'short';
						$jalali = $row->date ? $core->format_jalali_display( strtotime( $row->date ), $format ) : '';
						?>
						<div class="crocina-dashboard-chart-bar">
							<span class="crocina-dashboard-chart-value"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
							<div class="crocina-dashboard-chart-fill" style="height: <?php echo esc_attr( $height ); ?>%;"></div>
							<span class="crocina-dashboard-chart-label"><?php echo esc_html( $jalali ?: ( $row->date ?? '' ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( $daily_stats ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date (Jalali)', 'crocina-forms' ); ?></th>
							<th><?php esc_html_e( 'Count', 'crocina-forms' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $daily_stats, 0, $stats_days ) as $row ) : ?>
							<tr>
								<td>
									<?php
									$format = $settings['jalali_date_format'] ?? 'short';
									$jalali = $row->date ? $core->format_jalali_display( strtotime( $row->date ), $format ) : '';
									echo esc_html( $jalali ?: ( $row->date ?? '' ) );
									?>
								</td>
								<td><?php echo esc_html( number_format_i18n( absint( $row->count ?? 0 ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
