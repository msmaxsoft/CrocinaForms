<?php
/**
 * Crocina AJAX Handlers
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

final class Crocina_Ajax {

	use Crocina_Input_Helper;

	/** @var Crocina_App */
	private $app;

	/**
	 * @param Crocina_App $app
	 */
	public function __construct( Crocina_App $app ) {
		$this->app = $app;
	}

	public function init() {
		add_action( 'wp_ajax_crocina_submit_form', array( $this, 'handle_form_submit' ) );
		add_action( 'wp_ajax_nopriv_crocina_submit_form', array( $this, 'handle_form_submit' ) );
		add_action( 'wp_ajax_crocina_get_nonce', array( $this, 'handle_get_nonce' ) );
		add_action( 'wp_ajax_nopriv_crocina_get_nonce', array( $this, 'handle_get_nonce' ) );

		add_action( 'wp_ajax_crocina_watermark_preview', array( $this, 'handle_watermark_preview' ) );
		add_action( 'wp_ajax_crocina_batch_watermark', array( $this, 'handle_batch_watermark' ) );
		add_action( 'wp_ajax_crocina_clear_event_log', array( $this, 'handle_clear_event_log' ) );
		add_action( 'wp_ajax_crocina_export_form', array( $this, 'handle_export_form' ) );
		add_action( 'wp_ajax_crocina_import_form', array( $this, 'handle_import_form' ) );
		add_action( 'wp_ajax_crocina_test_notification', array( $this, 'handle_test_notification' ) );
		add_action( 'wp_ajax_crocina_mark_read', array( $this, 'handle_mark_read' ) );
		add_action( 'wp_ajax_crocina_quick_view_log', array( $this, 'handle_quick_view_log' ) );
		add_action( 'wp_ajax_crocina_system_refresh', array( $this, 'handle_system_refresh' ) );
		add_action( 'wp_ajax_crocina_test_channel', array( $this, 'handle_test_channel' ) );
		add_action( 'wp_ajax_crocina_get_channel_errors', array( $this, 'handle_get_channel_errors' ) );
		add_action( 'wp_ajax_crocina_mark_all_read', array( $this, 'handle_mark_all_read' ) );
		add_action( 'wp_ajax_crocina_save_settings', array( $this, 'handle_save_settings' ) );
	}

	public function handle_clear_event_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'crocina-forms' ) ) );
		}

		check_ajax_referer( 'crocina_clear_event_log', 'nonce' );

		$uploads  = wp_get_upload_dir();
		$log_file = ( $uploads['basedir'] ?? '' ) . '/crocina-forms/events.log';

		if ( ! is_file( $log_file ) ) {
			wp_send_json_success( array( 'message' => __( 'Log file does not exist.', 'crocina-forms' ) ) );
		}

		$cleared = file_put_contents( $log_file, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		if ( false === $cleared ) {
			wp_send_json_error( array( 'message' => __( 'Unable to clear the log file. Check file permissions.', 'crocina-forms' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Event log cleared successfully.', 'crocina-forms' ) ) );
	}

	public function handle_watermark_preview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', 403 );
		}

		// Read-only image preview.  The capability check above is the
		// primary gate; we skip the nonce requirement for GET image src
		// requests (the browser img tag cannot easily pass a nonce).
		// This is safe because:
		//   1. Only logged-in admins can reach this handler.
		//   2. The endpoint only generates/reads images — no mutations.

		nocache_headers();

		// Read current settings (query param overrides for live preview).
		$core = $this->app->get( 'core' );
		$settings = $core ? $core->get_global_settings() : array();

		// Allow query-param overrides so JS can send current form values.
		// Use empty string / 0 instead of null to prevent ltrim(null) deprecations.
		$overrides = array(
			'watermark_type'         => $this->has_get( 'type' )          ? sanitize_key( $this->input_get( 'type' ) ) : '',
			'watermark_text'         => $this->has_get( 'text' )          ? sanitize_text_field( wp_unslash( $this->input_get( 'text' ) ) ) : '',
			'watermark_position'     => $this->has_get( 'position' )      ? sanitize_key( $this->input_get( 'position' ) ) : '',
			'watermark_opacity'      => $this->has_get( 'opacity' )       ? absint( $this->input_get( 'opacity' ) ) : 0,
			'watermark_font_size'    => $this->has_get( 'font_size' )     ? absint( $this->input_get( 'font_size' ) ) : 0,
			'watermark_color'        => $this->has_get( 'color' )         ? sanitize_hex_color( wp_unslash( $this->input_get( 'color' ) ) ) : '',
			'watermark_logo_id'      => $this->has_get( 'logo_id' )       ? absint( $this->input_get( 'logo_id' ) ) : 0,
			'watermark_logo_max_width' => $this->has_get( 'logo_max_width' ) ? absint( $this->input_get( 'logo_max_width' ) ) : 0,
			'watermark_logo_opacity' => $this->has_get( 'logo_opacity' )  ? absint( $this->input_get( 'logo_opacity' ) ) : 0,
		);
		foreach ( $overrides as $key => $value ) {
			if ( null !== $value && '' !== $value ) {
				$settings[ $key ] = $value;
			}
		}

		// Use site name as fallback text.
		if ( empty( $settings['watermark_text'] ) ) {
			$settings['watermark_text'] = get_bloginfo( 'name' ) ?: 'Crocina Forms';
		}

		// Compute a cache key from all watermark-relevant settings.
		$cache_parts = array(
			'type'           => $settings['watermark_type'] ?? 'text',
			'text'           => $settings['watermark_text'] ?? '',
			'position'       => $settings['watermark_position'] ?? 'bottom-right',
			'opacity'        => $settings['watermark_opacity'] ?? 40,
			'font_size'      => $settings['watermark_font_size'] ?? 24,
			'color'          => $settings['watermark_color'] ?? '#ffffff',
			'logo_id'        => $settings['watermark_logo_id'] ?? 0,
			'logo_max_width' => $settings['watermark_logo_max_width'] ?? 120,
			'logo_opacity'   => $settings['watermark_logo_opacity'] ?? 60,
			'sample_id'      => $settings['watermark_sample_image_id'] ?? 0,
		);
		$cache_key  = md5( serialize( $cache_parts ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$ttl_secs   = 5;

		$uploads       = wp_get_upload_dir();
		$cache_dir     = $uploads['basedir'] . '/crocina-forms/watermark-cache';
		$cache_path    = $cache_dir . '/' . $cache_key . '.png';
		$cache_url     = $uploads['baseurl'] . '/crocina-forms/watermark-cache/' . $cache_key . '.png';

		// Create cache directory if it does not exist.
		if ( ! is_dir( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
		}

		// Serve cached file if it exists and is still fresh.
		if ( is_file( $cache_path ) && ( time() - filemtime( $cache_path ) ) < $ttl_secs ) {
			header( 'Content-Type: image/png' );
			header( 'X-Crocina-Cache: HIT' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
			readfile( $cache_path );
			exit;
		}

		// Clean stale cache files older than 60 seconds (prevent unbounded growth).
		$this->clean_watermark_cache( $cache_dir, 60 );

		// GD check.
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			header( 'Content-Type: image/svg+xml' );
			echo '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg" width="480" height="320" viewBox="0 0 480 320"><rect width="480" height="320" fill="#f0f2f5"/><text x="240" y="140" text-anchor="middle" fill="#d63638" font-size="18" font-family="sans-serif">' . esc_html__( 'GD library not available', 'crocina-forms' ) . '</text><text x="240" y="170" text-anchor="middle" fill="#6b738a" font-size="14" font-family="sans-serif">' . esc_html__( 'Install php-gd extension or ask your host.', 'crocina-forms' ) . '</text></svg>';
			exit;
		}

		// Resolve the custom sample image path if the user uploaded one.
		$sample_image_id = absint( $settings['watermark_sample_image_id'] ?? 0 );
		if ( $sample_image_id ) {
			$sample_path = get_attached_file( $sample_image_id );
			if ( $sample_path && is_file( $sample_path ) && is_readable( $sample_path ) ) {
				$settings['sample_image_path'] = $sample_path;
			}
		}

		// Generate the preview image.
		$watermark = new Crocina_Watermark();

		// Capture the PNG output into a string so we can cache it.
		ob_start();
		$watermark->output_preview_png( $settings );
		$png_data = ob_get_clean();

		// Cache the generated PNG on disk (for future hits).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		file_put_contents( $cache_path, $png_data );

		// Serve directly from the buffer — no need to re-read from disk.
		header( 'Content-Type: image/png' );
		header( 'X-Crocina-Cache: MISS' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $png_data;
		exit;
	}

	/**
	 * Remove stale watermark cache files older than the given threshold.
	 *
	 * @param string $dir     Cache directory path.
	 * @param int    $max_age Maximum age in seconds before a file is considered stale.
	 * @return void
	 */
	private function clean_watermark_cache( $dir, $max_age = 60 ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$now = time();
		$handle = opendir( $dir );
		if ( ! $handle ) {
			return;
		}

		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_file( $path ) && ( $now - filemtime( $path ) ) > $max_age ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		closedir( $handle );
	}


	/**
	 * AJAX handler — batch watermark existing media library images.
	 *
	 * Uses chunked processing: the first call scans the library and stores
	 * pending IDs in a transient. Subsequent calls process a chunk until
	 * all images are done. This avoids PHP timeouts on large libraries.
	 *
	 * POST params:
	 *   nonce   — crocina_batch_watermark
	 *   chunk   — number of images to process per request (default 5)
	 *
	 * Response:
	 *   total      — total number of images found
	 *   processed  — how many have been processed so far
	 *   success    — how many succeeded
	 *   failed     — how many failed (list of error messages)
	 *   done       — bool, true when all images are processed
	 */
	public function handle_batch_watermark() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'crocina-forms' ) ) );
		}
		check_ajax_referer( 'crocina_batch_watermark', 'nonce' );

		// Validate that watermark is enabled.
		$core = $this->app->get( 'core' );
		$settings = $core ? $core->get_global_settings() : array();
		if ( empty( $settings['watermark_enabled'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Watermark is not enabled. Enable it in the watermark settings first.', 'crocina-forms' ) ) );
		}

		// Validate GD is available.
		if ( ! function_exists( 'imagecreatetruecolor' ) && ! extension_loaded( 'imagick' ) ) {
			wp_send_json_error( array( 'message' => __( 'No image processing library (GD/Imagick) available.', 'crocina-forms' ) ) );
		}

		$chunk_size = max( 1, min( 50, absint( $this->input_post( 'chunk', 5 ) ) ) );
		$transient_key = 'crocina_batch_wm_progress';
		$progress = get_transient( $transient_key );

		$is_scan = ! empty( $this->input_post( 'scan' ) );

		/*
		 * Prevent concurrent runs from two browser tabs (only on scan initiation).
		 */
		if ( $is_scan && is_array( $progress ) && ! empty( $progress['running'] ) ) {
			wp_send_json_error( array(
				'message' => __( 'A batch watermark operation is already in progress. Wait for it to finish or reload the page.', 'crocina-forms' ),
			) );
		}

		/*
		 * First call — scan the media library and initialise progress.
		 * The JS sends `scan=1` only on the very first request.
		 */
		if ( $is_scan || false === $progress || ! is_array( $progress ) || ! isset( $progress['pending'] ) ) {
			$attachments = get_posts( array(
				'post_type'      => 'attachment',
				'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif' ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			) );

			if ( empty( $attachments ) ) {
				wp_send_json_success( array(
					'total'     => 0,
					'processed' => 0,
					'success'   => 0,
					'failed'    => array(),
					'done'      => true,
					'message'   => __( 'No images found in the media library.', 'crocina-forms' ),
				) );
			}

			$progress = array(
				'pending'   => $attachments,
				'success'   => array(),
				'failed'    => array(),
				'total'     => count( $attachments ),
				'running'   => true,
			);
		}

		/*
		 * Process a chunk of pending images.
		 */
		$watermark = new Crocina_Watermark();
		$to_process = array_splice( $progress['pending'], 0, $chunk_size );

		foreach ( $to_process as $attachment_id ) {
			$file_path = get_attached_file( $attachment_id );
			if ( ! $file_path || ! is_file( $file_path ) ) {
				$progress['failed'][] = sprintf(
					__( 'Attachment #%d: file not found.', 'crocina-forms' ),
					$attachment_id
				);
				continue;
			}

			try {
				$result = $watermark->apply( $file_path, $settings );
				if ( $result ) {
					$progress['success'][] = $attachment_id;
				} else {
					$progress['failed'][] = sprintf(
						__( 'Attachment #%d: watermark apply returned false (unsupported format or small image).', 'crocina-forms' ),
						$attachment_id
					);
				}
			} catch ( Exception $e ) {
				$progress['failed'][] = sprintf(
					__( 'Attachment #%d: %s', 'crocina-forms' ),
					$attachment_id,
					$e->getMessage()
				);
			}
		}

		$is_done = empty( $progress['pending'] );

		if ( $is_done ) {
			delete_transient( $transient_key );
		} else {
			$progress['running'] = true;
			set_transient( $transient_key, $progress, MINUTE_IN_SECONDS * 5 );
		}

		wp_send_json_success( array(
			'total'     => $progress['total'],
			'processed' => count( $progress['success'] ) + count( $progress['failed'] ),
			'success'   => count( $progress['success'] ),
			'failed'    => $progress['failed'],
			'done'      => $is_done,
			'message'   => $is_done
				? sprintf(
					__( 'Batch watermark complete. %d succeeded, %d failed.', 'crocina-forms' ),
					count( $progress['success'] ),
					count( $progress['failed'] )
				)
				: sprintf(
					__( 'Processing… %d of %d images done.', 'crocina-forms' ),
					count( $progress['success'] ) + count( $progress['failed'] ),
					$progress['total']
				),
		) );
	}


	public function handle_form_submit() {
		/** @var Crocina_Forms_Core $core */
		$core = $this->app->get( 'core' );
		if ( $core ) {
			$core->handle_ajax_submission();
		}
	}

	public function handle_get_nonce() {
		$form_id = absint( $this->input_request( 'form_id' ) );
		if ( ! $form_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid form.', 'crocina-forms' ) ) );
		}

		$form = get_post( $form_id );
		if ( ! $form || 'crocina_form' !== $form->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Form not found.', 'crocina-forms' ) ) );
		}

		nocache_headers();
		wp_send_json_success( array(
			'form_id'    => $form_id,
			'nonce'      => wp_create_nonce( 'crocina_form_' . $form_id ),
			'ajax_nonce' => wp_create_nonce( 'crocina_form_ajax_' . $form_id ),
		) );
	}

	/**
	 * AJAX handler — export a single form as JSON.
	 */
	public function handle_export_form() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'crocina-forms' ) ) );
		}
		check_ajax_referer( 'crocina_export_form', 'nonce' );

		$form_id = absint( $this->input_post( 'form_id' ) );
		$form    = get_post( $form_id );
		if ( ! $form || 'crocina_form' !== $form->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Form not found.', 'crocina-forms' ) ) );
		}

		$payload = array(
			'__meta' => array(
				'exported_at'    => gmdate( 'Y-m-d H:i:s' ),
				'format_version' => '1.0',
				'plugin_version' => CROCINA_FORMS_VERSION,
				'type'           => 'crocina_single_form',
			),
			'form' => array(
				'title'  => get_the_title( $form ),
				'slug'   => $form->post_name,
				'status' => $form->post_status,
			),
			'fields' => get_post_meta( $form_id, 'crocina_fields', true ),
			'design' => get_post_meta( $form_id, 'crocina_form_design', true ),
			'alerts' => get_post_meta( $form_id, 'crocina_alert_options', true ),
		);

		$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			wp_send_json_error( array( 'message' => __( 'Failed to encode form data.', 'crocina-forms' ) ) );
		}

		wp_send_json_success( array(
			'json'       => $json,
			'form_title' => $payload['form']['title'],
		) );
	}

	/**
	 * AJAX handler — import a single form from JSON.
	 */
	public function handle_import_form() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'crocina-forms' ) ) );
		}
		check_ajax_referer( 'crocina_import_single_form', 'nonce' );

		$form_id = absint( $this->input_post( 'form_id' ) );
		$form    = get_post( $form_id );
		if ( ! $form || 'crocina_form' !== $form->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Form not found.', 'crocina-forms' ) ) );
		}

		$raw   = wp_unslash( $this->input_post( 'payload' ) );
		$data  = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid JSON payload.', 'crocina-forms' ) ) );
		}

		// Update form title/slug/status if provided.
		$form_update = array( 'ID' => $form_id );
		if ( ! empty( $data['form']['title'] ) ) {
			$form_update['post_title'] = sanitize_text_field( $data['form']['title'] );
		}
		if ( ! empty( $data['form']['slug'] ) ) {
			$form_update['post_name'] = sanitize_title( $data['form']['slug'] );
		}
		if ( ! empty( $data['form']['status'] ) ) {
			$allowed_statuses = array( 'publish', 'draft', 'private' );
			$status = sanitize_key( $data['form']['status'] );
			if ( in_array( $status, $allowed_statuses, true ) ) {
				$form_update['post_status'] = $status;
			}
		}
		remove_action( 'save_post_crocina_form', array( $this->app->resolve( 'admin_meta_boxes' ), 'save_form_meta' ), 10 );
		wp_update_post( $form_update );
		add_action( 'save_post_crocina_form', array( $this->app->resolve( 'admin_meta_boxes' ), 'save_form_meta' ), 10, 2 );

		// Import fields, design, alerts.
		if ( isset( $data['fields'] ) ) {
			if ( is_array( $data['fields'] ) && ! empty( $data['fields'] ) ) {
				update_post_meta( $form_id, 'crocina_fields', $data['fields'] );
			} else {
				delete_post_meta( $form_id, 'crocina_fields' );
			}
		}
		if ( isset( $data['design'] ) && is_array( $data['design'] ) ) {
			update_post_meta( $form_id, 'crocina_form_design', $data['design'] );
		}
		if ( isset( $data['alerts'] ) && is_array( $data['alerts'] ) ) {
			update_post_meta( $form_id, 'crocina_alert_options', $data['alerts'] );
		}

		wp_send_json_success( array( 'message' => __( 'Form imported successfully. Reloading…', 'crocina-forms' ) ) );
	}

	/**
	 * AJAX handler — send a test notification to all enabled channels.
	 */
	public function handle_test_notification() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'crocina-forms' ) ) );
		}
		check_ajax_referer( 'crocina_test_notification', 'nonce' );

		$form_id = absint( $this->input_post( 'form_id' ) );
		$form    = get_post( $form_id );
		if ( ! $form || 'crocina_form' !== $form->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Form not found.', 'crocina-forms' ) ) );
		}

		// Build a sample payload mimicking a real submission.
		$sample_fields = array(
			array(
				'label' => __( 'Full Name', 'crocina-forms' ),
				'value' => __( 'John Doe', 'crocina-forms' ),
			),
			array(
				'label' => __( 'Email', 'crocina-forms' ),
				'value' => 'john@example.com',
			),
			array(
				'label' => __( 'Message', 'crocina-forms' ),
				'value' => __( 'This is a test message from the Crocina Forms plugin.', 'crocina-forms' ),
			),
		);

		$now_utc  = gmdate( 'Y-m-d H:i:s' );
		$gmt_off  = (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
		$now_local = gmdate( 'Y-m-d H:i:s', time() + $gmt_off );

		$sample_payload = array(
			'form_id'            => $form_id,
			'page_title'         => get_the_title( $form ) . ' ' . __( '(test)', 'crocina-forms' ),
			'page_url'           => get_permalink( $form ),
			'submitted_at'       => $now_utc,
			'submitted_at_jalali' => $now_local,
			'user_ip'            => '127.0.0.1',
			'fields'             => $sample_fields,
			'attachments'        => array(),
			'is_test'            => true,
		);

		/** @var Crocina_Notifications $notifications */
		$notifications = $this->app->get( 'notifications' );
		if ( ! $notifications ) {
			wp_send_json_error( array( 'message' => __( 'Notification service unavailable.', 'crocina-forms' ) ) );
		}

		$results = $notifications->dispatch( $form_id, $sample_payload );

		// Build a human-readable summary per channel.
		$summary = array();
		foreach ( $results as $channel => $result ) {
			if ( 'webhooks' === $channel && is_array( $result ) ) {
				$total    = count( $result );
				$success  = count( array_filter( $result ) );
				$summary['webhook'] = array(
					'success' => $success === $total,
					'label'   => sprintf( __( 'Webhooks (%d/%d)', 'crocina-forms' ), $success, $total ),
					'message' => $success === $total
						? sprintf( __( 'All %d webhook(s) sent.', 'crocina-forms' ), $total )
						: sprintf( __( '%d of %d webhook(s) failed.', 'crocina-forms' ), $total - $success, $total ),
				);
			} else {
				$channel_labels = array(
					'email'  => __( 'Email', 'crocina-forms' ),
					'telegram' => __( 'Telegram', 'crocina-forms' ),
					'bale'     => __( 'Bale', 'crocina-forms' ),
					'eitaa'    => __( 'Eitaa', 'crocina-forms' ),
					'rubika'   => __( 'Rubika', 'crocina-forms' ),
					'whatsapp' => __( 'WhatsApp', 'crocina-forms' ),
				);
				$label = $channel_labels[ $channel ] ?? ucfirst( $channel );
				$summary[ $channel ] = array(
					'success' => ! empty( $result ),
					'label'   => $label,
					'message' => $result
						? sprintf( __( '%s: Sent successfully.', 'crocina-forms' ), $label )
						: sprintf( __( '%s: Failed. Check your configuration.', 'crocina-forms' ), $label ),
				);
			}
		}

		wp_send_json_success( array(
			'summary' => $summary,
			'message' => empty( $summary )
				? __( 'No channels are enabled for this form.', 'crocina-forms' )
				: __( 'Test notification sent.', 'crocina-forms' ),
		) );
	}

	/**
	 * AJAX handler — mark a log row as read.
	 *
	 * POST params:
	 *   nonce   — crocina_mark_read
	 *   log_id  — the log entry ID to mark as read
	 */
	public function handle_mark_read() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'crocina-forms' ) ) );
		}
		check_ajax_referer( 'crocina_mark_read', 'nonce' );

		$log_id = absint( $this->input_post( 'log_id' ) );
		if ( ! $log_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid log ID.', 'crocina-forms' ) ) );
		}

		/** @var Crocina_Logger $logger */
		$logger = $this->app->get( 'logger' );
		if ( ! $logger ) {
			wp_send_json_error( array( 'message' => __( 'Logger unavailable.', 'crocina-forms' ) ) );
		}

		$result = $logger->mark_read( $log_id );
		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Marked as read.', 'crocina-forms' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Failed to mark as read.', 'crocina-forms' ) ) );
	}

	/**
	 * AJAX handler — mark all logs for a given form (or all forms) as read.
	 *
	 * POST params:
	 *   nonce    — crocina_mark_all_read
	 *   form_id  — optional form ID to scope the update (0 = all forms)
	 */
	public function handle_mark_all_read() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'crocina-forms' ) ) );
		}
		check_ajax_referer( 'crocina_mark_all_read', 'nonce' );

		$form_id = absint( $this->input_post( 'form_id' ) );

		/** @var Crocina_Logger $logger */
		$logger = $this->app->get( 'logger' );
		if ( ! $logger ) {
			wp_send_json_error( array( 'message' => __( 'Logger unavailable.', 'crocina-forms' ) ) );
		}

		$updated = $logger->mark_all_read( $form_id );

		wp_send_json_success( array(
			'message'      => sprintf( __( '%d entries marked as read.', 'crocina-forms' ), $updated ),
			'updated'      => $updated,
			'total_unread' => $logger->get_unread_count( $form_id ),
		) );
	}

	/**
	 * AJAX handler — quick-view a single log entry.
	 *
	 * POST params:
	 *   nonce   — crocina_quick_view_log
	 *   log_id  — the log entry ID to view
	 */
	public function handle_quick_view_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'crocina-forms' ) ) );
		}
		check_ajax_referer( 'crocina_quick_view_log', 'nonce' );

		$log_id = absint( $this->input_post( 'log_id' ) );
		if ( ! $log_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid log ID.', 'crocina-forms' ) ) );
		}

		/** @var Crocina_Logger $logger */
		$logger = $this->app->get( 'logger' );
		if ( ! $logger ) {
			wp_send_json_error( array( 'message' => __( 'Logger unavailable.', 'crocina-forms' ) ) );
		}

		$log = $logger->get_log( $log_id );
		if ( ! $log ) {
			wp_send_json_error( array( 'message' => __( 'Log not found.', 'crocina-forms' ) ) );
		}

		// Build structured field data for safe client-side rendering.
		$payload = json_decode( $log->payload, true );
		$fields_data = array();
		$rendered_fields = '';

		if ( is_array( $payload ) ) {
			$raw_fields = $payload['fields'] ?? $payload;
			if ( is_array( $raw_fields ) ) {
				foreach ( $raw_fields as $field ) {
					if ( is_array( $field ) && isset( $field['label'], $field['value'] ) ) {
						$fields_data[] = array(
							'label' => esc_html( wp_unslash( $field['label'] ) ),
							'value' => esc_html( wp_unslash( is_array( $field['value'] ) ? implode( ', ', $field['value'] ) : $field['value'] ) ),
						);
					} elseif ( is_array( $field ) ) {
						$label = key( $field );
						$value = is_array( $field[ $label ] ) ? implode( ', ', $field[ $label ] ) : $field[ $label ];
						$fields_data[] = array(
							'label' => esc_html( wp_unslash( $label ) ),
							'value' => esc_html( wp_unslash( $value ) ),
						);
					} else {
						$fields_data[] = array(
							'label' => '',
							'value' => esc_html( wp_unslash( (string) $field ) ),
						);
					}
				}
			}
		}

		wp_send_json_success( array(
			'form_id'    => $log->form_id,
			'form'       => get_the_title( $log->form_id ) ?: '#' . $log->form_id,
			'date'       => $log->submitted_at,
			'ip'         => $log->user_ip,
			'user_agent' => esc_html( $log->user_agent ?? '' ),
			'page'       => $log->page_url,
			'fieldsData' => $fields_data,
		) );
	}

	/**
	 * AJAX handler — refresh system status data in real-time.
	 *
	 * Returns fresh cache stats, database status, form count, and log count
	 * for the System Status tab.  This allows the admin UI to refresh without
	 * reloading the entire Settings page.
	 *
	 * @return void
	 */
	/**
	 * Gather comprehensive system health data for the System Status tab.
	 *
	 * Merges cache stats, DB status, capability mapping, REST API health,
	 * WP-CLI commands list, error_log guarding analysis, and environment info
	 * into one AJAX response so the frontend can render a professional dashboard.
	 *
	 * @return array<string,mixed>
	 */
	private function gather_system_data() {
		$core   = $this->app->get( 'core' );
		$logger = $this->app->get( 'logger' );
		$admin  = $this->app->get( 'admin' );

		$cache_stats = $core ? $core->get_cache_stats() : array();
		$db_status   = $logger ? $logger->get_db_status() : null;

		$total_forms   = wp_count_posts( 'crocina_form' )->publish ?? 0;
		$total_logs    = $logger ? $logger->get_logs_count() : 0;
		$unread_logs   = $logger ? $logger->get_unread_count() : 0;

		/* ---- Capability mapping ---- */
		$capabilities = array(
			'manage_options' => current_user_can( 'manage_options' ),
			'edit_posts'     => current_user_can( 'edit_posts' ),
			'edit_post'      => current_user_can( 'edit_post', 0 ),
			'unfiltered_html' => current_user_can( 'unfiltered_html' ),
		);

		/* ---- REST API health ---- */
		$rest_routes = array(
			'GET /crocina/v1/forms'                    => 'List forms',
			'GET /crocina/v1/forms/{id}'               => 'Single form + design',
			'GET /crocina/v1/forms/{id}/fields'         => 'Form fields only',
			'POST /crocina/v1/forms/{id}/reorder-fields'=> 'Reorder fields',
		);
		/* Check if each route is actually registered with the server. */
		$rest_server = rest_get_server();
		$all_routes  = $rest_server->get_routes();
		$rest_health = array();
		foreach ( $rest_routes as $route => $desc ) {
			$method = explode( ' ', $route )[0];
			$path   = trim( substr( $route, strlen( $method ) ) );

			/* Normalise the path so we can compare against registered route patterns:
			 * replace {id} with a capture-group placeholder.                    */
			$normalised_path = preg_replace( '#\{[^}]+\}#', '{param}', $path );
			$is_registered   = false;

			foreach ( $all_routes as $route_pattern => $handlers ) {
				$normalised_pattern = preg_replace( '#\{[^}]+\}#', '{param}', $route_pattern );
				if ( $normalised_path === $normalised_pattern ) {
					/* Verify at least one handler supports the HTTP method. */
					foreach ( $handlers as $handler ) {
						$handler_methods = array();
						if ( isset( $handler['methods'] ) ) {
							if ( is_array( $handler['methods'] ) ) {
								$handler_methods = array_keys( $handler['methods'] );
							} else {
								$handler_methods = array( strtoupper( $handler['methods'] ) );
							}
						}
						if ( in_array( strtoupper( $method ), $handler_methods, true ) ) {
							$is_registered = true;
							break 2;
						}
					}
				}
			}

			$rest_health[] = array(
				'method'  => $method,
				'route'   => $path,
				'desc'    => $desc,
				'status'  => $is_registered ? 'registered' : 'check',
			);
		}

		/* ---- WP-CLI commands ---- */
		$cli_commands = array(
			'crocina cache'       => 'Flush, warm, or show cache status',
			'crocina events'      => 'Export, stats, webhook test for event log',
			'crocina form'        => 'List forms with field and cache info',
			'crocina warm-cache'  => 'Pre-build cache for all active forms',
			'crocina preload'     => 'Alias for warm-cache',
			'crocina webhook'     => 'Send test webhook to endpoint',
			'crocina security'    => 'Scan AJAX handlers for nonce/capability',
		);

		/* ---- error_log guarding ---- */
		$wp_debug      = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$wp_debug_log  = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
		$wp_debug_display = defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;

		$error_log_note = '';
		if ( $wp_debug && $wp_debug_display ) {
			$error_log_note = 'Errors may be displayed publicly. Set WP_DEBUG_DISPLAY to false in production.';
		} elseif ( $wp_debug && ! $wp_debug_display && $wp_debug_log ) {
			$error_log_note = 'Errors logged to wp-content/debug.log — safe for production.';
		} elseif ( $wp_debug && ! $wp_debug_display ) {
			$error_log_note = 'Debug mode active but display disabled. Errors logged only.';
		} else {
			$error_log_note = 'WP_DEBUG is disabled — safe for production.';
		}

		/* ---- Environment info ---- */
		global $wpdb;
		$php_version = phpversion();
		$wp_version  = get_bloginfo( 'version' );
		$mysql_ver   = $wpdb->db_version();
		$server_soft = sanitize_text_field( $this->input_server( 'SERVER_SOFTWARE' ) );
		$memory_limit = ini_get( 'memory_limit' );
		$max_execution = ini_get( 'max_execution_time' );
		$uploads_max = ini_get( 'upload_max_filesize' );
		$post_max    = ini_get( 'post_max_size' );

		return array(
			'cache_stats'      => $cache_stats,
			'cache_backend'    => isset( $cache_stats['cache_backend'] ) ? $cache_stats['cache_backend'] : 'default',
			'db_status'        => $db_status,
			'total_forms'      => (int) $total_forms,
			'total_logs'       => $total_logs,
			'unread_logs'      => $unread_logs,
			'capabilities'     => $capabilities,
			'rest_health'      => $rest_health,
			'cli_commands'     => $cli_commands,
			'wp_debug'         => $wp_debug,
			'wp_debug_log'     => $wp_debug_log,
			'wp_debug_display' => $wp_debug_display,
			'error_log_note'   => $error_log_note,
			'environment'      => array(
				'php_version'    => $php_version,
				'wp_version'     => $wp_version,
				'mysql_version'  => $mysql_ver,
				'server_soft'    => $server_soft,
				'memory_limit'   => $memory_limit,
				'max_execution'  => $max_execution,
				'uploads_max'    => $uploads_max,
				'post_max'       => $post_max,
				'object_cache'   => wp_using_ext_object_cache(),
			),
			'timestamp'        => time(),
		);
	}

	/**
	 * AJAX handler — send a test message to a specific notification channel
	 * using the global (settings-page) credentials.
	 *
	 * POST params:
	 *   nonce   — crocina_test_channel
	 *   channel — one of 'telegram', 'bale', 'eitaa', 'rubika', 'whatsapp'
	 *
	 * @return void
	 */
	public function handle_test_channel() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'crocina-forms' ) ) );
		}
		check_ajax_referer( 'crocina_test_channel', 'nonce' );

		$channel = sanitize_key( $this->input_post( 'channel' ) );
		$allowed = array( 'telegram', 'bale', 'eitaa', 'rubika', 'whatsapp' );
		if ( ! in_array( $channel, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid channel.', 'crocina-forms' ) ) );
		}

		/** @var Crocina_Forms_Core $core */
		$core = $this->app->get( 'core' );
		if ( ! $core ) {
			wp_send_json_error( array( 'message' => __( 'Core service unavailable.', 'crocina-forms' ) ) );
		}

		$core->preload_global_settings();
		$settings = $core->get_global_settings();

		$token_key  = $channel . '_token';
		$chat_key   = $channel . '_chat';
		$token      = sanitize_text_field( $settings[ $token_key ] ?? '' );
		$chat_id    = sanitize_text_field( $settings[ $chat_key ] ?? '' );

		if ( empty( $token ) || empty( $chat_id ) ) {
			wp_send_json_error( array(
				'message' => sprintf(
					__( 'Please configure the %s token and chat ID in the settings above, then save before testing.', 'crocina-forms' ),
					ucfirst( $channel )
				),
			) );
		}

		// Build a short test message.
		$site_name = get_bloginfo( 'name' ) ?: 'Crocina Forms';
		$test_text = sprintf(
			"✅ Test: %s\n\nConnection from Crocina Forms plugin successful!\nSite: %s\nTime: %s",
			ucfirst( $channel ),
			$site_name,
			wp_date( 'Y-m-d H:i:s' )
		);

		// Send via the Channel Registry — all channels (Telegram, Bale, Eitaa, Rubika, WhatsApp).
		$channel_instance = Crocina_Channel_Registry::get( $channel );
		if ( ! $channel_instance ) {
			wp_send_json_error( array( 'message' => sprintf( __( 'Channel "%s" is not registered.', 'crocina-forms' ), $channel ) ) );
		}

		/** @var Crocina_Notifications $notifications */
		$notifications = $this->app->get( 'notifications' );

		try {
			// Build the context array (3rd parameter required by the interface).
			$context = array(
				'form_id'      => 0,
				'form'         => null,
				'payload'      => array(
					'form_id'      => 0,
					'fields'       => array(
						array( 'label' => __( 'Name', 'crocina-forms' ), 'value' => __( 'Test User', 'crocina-forms' ) ),
						array( 'label' => __( 'Phone', 'crocina-forms' ), 'value' => __( '09120000000', 'crocina-forms' ) ),
					),
					'submitted_at' => current_time( 'mysql' ),
					'user_ip'      => '127.0.0.1',
					'page_title'   => __( 'Test Page', 'crocina-forms' ),
					'page_url'     => home_url(),
				),
				'replacements' => array(),
				'attachments'  => array(),
			);

			$result = $channel_instance->send( $test_text, array(
				'chat_id'  => $chat_id,
				'token'    => $token,
				'endpoint' => $settings[ $channel . '_endpoint' ] ?? '',
			), $context );

			if ( $result ) {
				wp_send_json_success( array( 'message' => __( 'Test message sent successfully!', 'crocina-forms' ) ) );
			}

			// Log the failure structurally with detailed context.
			$error_detail = '';
			if ( $channel_instance && method_exists( $channel_instance, 'get_last_error' ) ) {
				$error_detail = $channel_instance->get_last_error();
			}
			if ( $notifications ) {
				$notifications->log_channel_error( $channel, 'Test send failed — channel returned false.' . ( $error_detail ? ' ' . $error_detail : '' ), array(), 'test' );
			}

			// Provide a more useful error message.
			$error_msg = __( 'Failed to send test message.', 'crocina-forms' );
			if ( $error_detail ) {
				$error_msg .= ' ' . $error_detail;
			} else {
				$error_msg .= ' ' . __( 'Check that your token, chat ID, and endpoint are correct, then save before testing.', 'crocina-forms' );
			}

			wp_send_json_error( array( 'message' => $error_msg ) );
		} catch ( Exception $e ) {
			// Log the exception structurally.
			if ( $notifications ) {
				$notifications->log_channel_error( $channel, $e->getMessage(), array(), 'test' );
			}

			wp_send_json_error( array( 'message' => sprintf( __( 'Error: %s', 'crocina-forms' ), $e->getMessage() ) ) );
		}
	}

	/**
	 * AJAX handler — fetch channel error history from events.log.
	 *
	 * GET params:
	 *   nonce   — crocina_get_channel_errors
	 *   filter  — optional channel name to filter by
	 *   limit   — max entries (default 50, max 200)
	 *
	 * @return void
	 */
	public function handle_get_channel_errors() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'crocina-forms' ) ) );
		}
		check_ajax_referer( 'crocina_get_channel_errors', 'nonce' );

		$filter = $this->has_get( 'filter' ) ? sanitize_key( $this->input_get( 'filter' ) ) : '';
		$limit  = $this->has_get( 'limit' ) ? min( 200, max( 1, absint( $this->input_get( 'limit' ) ) ) ) : 50;

		/** @var Crocina_Notifications $notifications */
		$notifications = $this->app->get( 'notifications' );
		if ( ! $notifications ) {
			wp_send_json_error( array( 'message' => __( 'Notification service unavailable.', 'crocina-forms' ) ) );
		}

		$errors = $notifications->get_channel_error_log( $limit, $filter );

		wp_send_json_success( array(
			'errors' => $errors,
			'total'  => count( $errors ),
			'filter' => $filter,
		) );
	}

	/**
	 * AJAX handler — save global settings via AJAX.
	 *
	 * POST params:
	 *   nonce    — crocina_save_settings
	 *   settings — serialized form data (from jQuery.serialize())
	 *
	 * Parses the serialized data back into the crocina_forms_settings array,
	 * sanitizes it via Crocina_Admin::sanitize_settings(), and persists it
	 * with update_option().  Returns a proper success/error response so the
	 * UI can show inline feedback without a page reload.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'crocina-forms' ) ) );
		}
		check_ajax_referer( 'crocina_save_settings', 'nonce' );

		$serialized = $this->has_post( 'settings' ) ? wp_unslash( $this->input_post( 'settings' ) ) : '';
		if ( empty( $serialized ) ) {
			wp_send_json_error( array( 'message' => __( 'No settings data received.', 'crocina-forms' ) ) );
		}

		// Parse the serialized query string back into an array.
		// jQuery.serialize() produces key=value&key2=value2 pairs, so we
		// use parse_str() which safely reconstructs the PHP array from
		// PHP-style field names like crocina_forms_settings[key].
		parse_str( $serialized, $parsed );

		$raw_settings = isset( $parsed['crocina_forms_settings'] ) && is_array( $parsed['crocina_forms_settings'] )
			? $parsed['crocina_forms_settings']
			: array();

		if ( empty( $raw_settings ) ) {
			wp_send_json_error( array( 'message' => __( 'No valid settings found in the request.', 'crocina-forms' ) ) );
		}

		// Sanitize via the same callback used by WordPress Settings API.
		/** @var Crocina_Admin $admin */
		$admin = $this->app->get( 'admin' );
		if ( ! $admin ) {
			wp_send_json_error( array( 'message' => __( 'Admin service unavailable.', 'crocina-forms' ) ) );
		}

		$sanitized = $admin->sanitize_settings( $raw_settings );

		update_option( 'crocina_forms_settings', $sanitized );

		// Flush any cached settings so the next page load picks up the change.
		/** @var Crocina_Forms_Core $core */
		$core = $this->app->get( 'core' );
		if ( $core ) {
			$core->flush_cache();
		}

		wp_send_json_success( array(
			'message'  => __( 'Settings saved successfully.', 'crocina-forms' ),
			'flushed'  => true,
		) );
	}

	/**
	 * AJAX handler — refresh system status data in real-time.
	 *
	 * Returns comprehensive system health data: cache stats, DB status,
	 * capability mapping, REST API health, CLI commands, error_log guards,
	 * and environment info — all in one response.
	 *
	 * @return void
	 */
	public function handle_system_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}
		check_ajax_referer( 'crocina_system_refresh', 'nonce' );

		nocache_headers();

		$data = $this->gather_system_data();

		wp_send_json_success( $data );
	}
}
