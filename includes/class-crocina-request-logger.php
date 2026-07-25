<?php
/**
 * Crocina Request Logger — middleware that detects suspicious HTTP requests
 * and logs them to the plugin's events.log file.
 *
 * Inspects every crocina-related AJAX request (before the handler runs)
 * and every front-end form submission.  Suspicious patterns include:
 *
 *  - Missing nonce parameter
 *  - Request body > 100 KB (potential payload smuggling)
 *  - Known bot / empty User-Agent headers
 *  - Excessive POST field count (spam indicator)
 *  - Rapid-fire submissions from the same IP
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

class Crocina_Request_Logger {

	use Crocina_Input_Helper;

	/**
	 * The known AJAX action slugs that belong to Crocina Forms.
	 *
	 * @var string[]
	 */
	private $crocina_ajax_actions = array(
		// Crocina_Ajax handlers.
		'crocina_submit_form',
		'crocina_get_nonce',
		'crocina_get_eitaa_chat_id',
		'crocina_test_eitaa',
		'crocina_watermark_preview',
		'crocina_batch_watermark',
		'crocina_clear_event_log',
		'crocina_export_form',
		'crocina_import_form',
		'crocina_test_notification',
		'crocina_mark_read',
		'crocina_quick_view_log',
		// Crocina_Admin_Ajax_Handler (duplicates intentionally listed;
		// wp_ajax_ hook registration is idempotent).
		'crocina_preview_form',
		'crocina_quick_edit_title',
		'crocina_partial_export',
	);

	/**
	 * Transient key prefix for tracking per-IP submission frequency.
	 *
	 * @var string
	 */
	private const RATE_KEY_PREFIX = 'crocina_reqlog_rate_';

	/**
	 * Maximum number of submissions per IP within the rate window.
	 *
	 * @var int
	 */
	private const RATE_LIMIT = 10;

	/**
	 * Rate-window duration in seconds.
	 *
	 * @var int
	 */
	private const RATE_WINDOW = 30;

	/**
	 * Max POST body size in bytes before logging a "large payload" warning.
	 *
	 * @var int
	 */
	private const MAX_BODY_SIZE = 102400; // 100 KB

	/**
	 * Max number of individual POST fields before flagging as suspicious.
	 *
	 * @var int
	 */
	private const MAX_FIELD_COUNT = 100;

	/**
	 * Whether the middleware is enabled (controlled by global settings).
	 *
	 * @var bool
	 */
	private $enabled = false;

	/**
	 * Full path to the events.log file (shared with Crocina_Event_Logger).
	 *
	 * @var string
	 */
	private $log_file = '';

	/**
	 * Max log file size in bytes before rotation (5 MB).
	 *
	 * @var int
	 */
	private const MAX_LOG_SIZE = 5242880;

	/**
	 * @param array $settings Global plugin settings (from Crocina_Forms_Core::get_global_settings()).
	 */
	public function __construct( array $settings = array() ) {
		$this->enabled = ! empty( $settings['request_logging_enabled'] );
		if ( $this->enabled ) {
			$this->init_log_file();
		}
	}

	/**
	 * Register WordPress hooks.
	 *
	 * Called during plugin initialisation — must register AJAX hooks BEFORE
	 * the actual AJAX handlers register theirs, so that our inspection runs
	 * first (priority 0 vs their default priority 10).
	 *
	 * @return void
	 */
	public function init() {
		if ( ! $this->enabled || ! $this->log_file ) {
			return;
		}

		/*
		 * Hook into every known crocina AJAX action at priority 0 so our
		 * inspection callback fires before the actual handler.
		 */
		foreach ( $this->crocina_ajax_actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, 'inspect_ajax' ), 0 );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, 'inspect_ajax' ), 0 );
		}

		/*
		 * Inspect front-end form submissions before Crocina_Forms_Core
		 * processes them (priority 5 vs core's priority 10 on wp_loaded).
		 */
		add_action( 'wp_loaded', array( $this, 'inspect_form_submission' ), 5 );

		/*
		 * Also catch any submission attempt that does NOT come through our
		 * known AJAX actions — e.g. a direct POST to admin-ajax.php with
		 * an unrecognised action parameter.
		 */
		add_action( 'admin_init', array( $this, 'inspect_unknown_ajax' ), 0 );
	}

	/* ------------------------------------------------------------------ */
	/*  Inspectors                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Inspect an AJAX request BEFORE the registered handler runs.
	 *
	 * Reads the `action` parameter from the POST/GET payload and runs a
	 * series of heuristic checks.  Any red flag is written to events.log
	 * with a [SUSPICIOUS] prefix.
	 *
	 * @return void
	 */
	public function inspect_ajax() {
		$action = sanitize_key( wp_unslash( $this->input_request( 'action' ) ) );
		if ( ! $action ) {
			return;
		}

		$red_flags = array();

		// 1. Missing nonce parameter.
		if ( ! $this->has_nonce_param() ) {
			$red_flags[] = 'missing_nonce';
		}

		// 2. Large request body / oversized payload.
		$content_length = absint( $this->input_server( 'CONTENT_LENGTH', 0 ) );
		if ( $content_length > self::MAX_BODY_SIZE ) {
			$red_flags[] = 'large_body_' . $content_length;
		}

		// 3. Known bot or empty User-Agent.
		$ua = $this->get_user_agent();
		if ( '' === $ua ) {
			$red_flags[] = 'empty_user_agent';
		} elseif ( $this->is_known_bot( $ua ) ) {
			$red_flags[] = 'known_bot';
		}

		// 4. Excessive POST field count (spam indicator).
		$post_data = $this->get_post_array();
		if ( count( $post_data ) > self::MAX_FIELD_COUNT ) {
			$red_flags[] = 'excessive_fields_' . count( $post_data );
		}

		if ( ! empty( $red_flags ) ) {
			$this->log_suspicious( 'AJAX', $action, $red_flags );
		}
	}

	/**
	 * Inspect a front-end form submission (non-AJAX).
	 *
	 * Triggered on wp_loaded before Crocina_Forms_Core::maybe_handle_submission().
	 *
	 * @return void
	 */
	public function inspect_form_submission() {
		$form_id = absint( $this->input_post( 'crocina_form_id' ) );
		if ( ! $form_id ) {
			return;
		}
		$red_flags = array();

		// 1. Missing nonce field entirely.
		if ( ! $this->has_post( '_crocina_nonce' ) && ! $this->has_post( '_crocina_ajax_nonce' ) ) {
			$red_flags[] = 'missing_nonce';
		}

		// 2. Honeypot filled (bot detection).  Guard: form_id must be present.
		if ( $form_id ) {
			$honeypot_name = $this->get_honeypot_field_name( $form_id );
			if ( ! empty( $this->input_post( $honeypot_name ) ) ) {
				$red_flags[] = 'honeypot_triggered';
			}
		}

		// 3. Timestamp out of reasonable range.
		if ( $this->has_post( 'crocina_form_timestamp' ) ) {
			$ts = absint( $this->input_post( 'crocina_form_timestamp' ) );
			$now = time();
			if ( $ts > ( $now + 60 ) || $ts < ( $now - 3600 ) ) {
				$red_flags[] = 'invalid_timestamp';
			}
		} else {
			$red_flags[] = 'missing_timestamp';
		}

		// 4. Large request body.
		$content_length = absint( $this->input_server( 'CONTENT_LENGTH', 0 ) );
		if ( $content_length > self::MAX_BODY_SIZE ) {
			$red_flags[] = 'large_body_' . $content_length;
		}

		// 5. Known bot User-Agent.
		$ua = $this->get_user_agent();
		if ( '' === $ua ) {
			$red_flags[] = 'empty_user_agent';
		} elseif ( $this->is_known_bot( $ua ) ) {
			$red_flags[] = 'known_bot';
		}

		// 6. Rapid-fire rate check (same IP, multiple submissions).
		if ( $this->is_rapid_fire() ) {
			$red_flags[] = 'rapid_fire';
		}

		if ( ! empty( $red_flags ) ) {
			$this->log_suspicious( 'SUBMIT', 'form_' . $form_id, $red_flags );
		}
	}

	/**
	 * Catch admin-ajax.php requests with an unrecognised (non-crocina) action.
	 *
	 * This only triggers when the `action` parameter is present but does NOT
	 * match any known crocina action.  It helps detect direct POSTs to
	 * admin-ajax.php that may be trying to exploit generic WordPress actions.
	 *
	 * @return void
	 */
	public function inspect_unknown_ajax() {
		if ( ! wp_doing_ajax() ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $this->input_request( 'action' ) ) );
		if ( ! $action ) {
			return;
		}

		// Only care about requests that look like they target our plugin.
		if ( false === strpos( $action, 'crocina' ) ) {
			return;
		}

		// If it's already in our known list, we've already inspected it.
		if ( in_array( $action, $this->crocina_ajax_actions, true ) ) {
			return;
		}

		$this->log_suspicious( 'UNKNOWN_AJAX', $action, array( 'unknown_crocina_action' ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Logging                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Write a structured log entry for a suspicious request.
	 *
	 * @param string   $type      Request type: 'AJAX', 'SUBMIT', or 'UNKNOWN_AJAX'.
	 * @param string   $target    The action name or form identifier.
	 * @param string[] $red_flags List of detected issue codes.
	 * @return void
	 */
	private function log_suspicious( $type, $target, array $red_flags ) {
		if ( ! $this->log_file ) {
			return;
		}

		$this->rotate_if_needed();

		$time    = gmdate( 'Y-m-d H:i:s' );
		$ip      = $this->get_request_ip();
		$ua      = $this->get_user_agent();
		$method  = strtoupper( sanitize_key( $this->input_server( 'REQUEST_METHOD', 'GET' ) ) );
		$uri     = esc_url_raw( wp_unslash( $this->input_server( 'REQUEST_URI' ) ) );
		$flags   = implode( ', ', $red_flags );

		$entry = sprintf(
			'[%s] [SUSPICIOUS] [%s] %s | IP: %s | UA: %s | %s | URI: %s',
			$time,
			$type,
			str_pad( $target, 30 ),
			$ip,
			$ua ? substr( $ua, 0, 80 ) : '(empty)',
			$flags,
			$uri
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $entry . PHP_EOL, 3, $this->log_file );
	}

	/* ------------------------------------------------------------------ */
	/*  Heuristics                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Check whether the current request carries any popular nonce parameter.
	 *
	 * @return bool
	 */
	private function has_nonce_param() {
		if ( $this->has_post( 'nonce' ) || $this->has_get( 'nonce' ) ) {
			return true;
		}
		if ( $this->has_post( '_wpnonce' ) || $this->has_get( '_wpnonce' ) ) {
			return true;
		}
		if ( $this->has_post( '_ajax_nonce' ) || $this->has_get( '_ajax_nonce' ) ) {
			return true;
		}
		if ( $this->has_post( '_crocina_nonce' ) || $this->has_get( '_crocina_nonce' ) ) {
			return true;
		}
		if ( $this->has_post( '_crocina_ajax_nonce' ) || $this->has_get( '_crocina_ajax_nonce' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Check whether the same IP is submitting too often.
	 *
	 * Uses a transient to count submissions from the current IP within
	 * a sliding window of RATE_WINDOW seconds.  Each new request extends
	 * the window, which is a simple and safe approach for logging-only rate
	 * detection.
	 *
	 * @return bool True if rate limit exceeded.
	 */
	private function is_rapid_fire() {
		$ip  = $this->get_request_ip();
		$key = self::RATE_KEY_PREFIX . md5( $ip );

		$count = (int) get_transient( $key );
		++$count;

		if ( $count > self::RATE_LIMIT ) {
			return true;
		}

		// Extend the window on every request (sliding window approach).
		set_transient( $key, $count, self::RATE_WINDOW );

		return false;
	}

	/**
	 * Detect known bot and crawler User-Agent strings.
	 *
	 * @param string $ua The User-Agent header value.
	 * @return bool
	 */
	private function is_known_bot( $ua ) {
		$bot_patterns = array(
			'bot',
			'crawler',
			'spider',
			'scrape',
			'curl',
			'wget',
			'python-requests',
			'python-urllib',
			'go-http-client',
			'java/',
			'libwww',
			'httpclient',
			'phpcurl',
			'fetch',
			'node-fetch',
			'axios',
			'scan',
			'proxy',
			'mj12bot',
			'ahrefsbot',
			'semrushbot',
			'sitebulb',
			'netcraft',
			'turnitin',
		);

		$ua_lower = strtolower( $ua );

		foreach ( $bot_patterns as $pattern ) {
			if ( false !== strpos( $ua_lower, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/* ------------------------------------------------------------------ */
	/*  Helpers                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Initialise the log file path (shared with Crocina_Event_Logger).
	 *
	 * @return void
	 */
	private function init_log_file() {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			$this->enabled = false;
			return;
		}

		$log_dir = $uploads['basedir'] . '/crocina-forms';
		if ( ! is_dir( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		if ( ! is_dir( $log_dir ) ) {
			$this->enabled = false;
			return;
		}

		// Place an empty index.html to prevent directory listing.
		if ( ! is_file( $log_dir . '/index.html' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			file_put_contents( $log_dir . '/index.html', '' );
		}

		$this->log_file = $log_dir . '/events.log';
	}

	/**
	 * Rotate the log file if it exceeds MAX_LOG_SIZE.
	 *
	 * @return void
	 */
	private function rotate_if_needed() {
		if ( ! is_file( $this->log_file ) ) {
			return;
		}

		if ( filesize( $this->log_file ) < self::MAX_LOG_SIZE ) {
			return;
		}

		$backup = $this->log_file . '.1';
		if ( is_file( $backup ) ) {
			unlink( $backup );
		}
		rename( $this->log_file, $backup );
	}

	/**
	 * Get the client IP address, respecting trusted proxy headers.
	 *
	 * @return string
	 */
	private function get_request_ip() {
		$ip = '';

		if ( ! empty( $this->input_server( 'HTTP_X_FORWARDED_FOR' ) ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $this->input_server( 'HTTP_X_FORWARDED_FOR' ) ) ) );
			$ip  = trim( (string) array_shift( $ips ) );
		}

		if ( ! $ip && ! empty( $this->input_server( 'HTTP_X_REAL_IP' ) ) ) {
			$ip = sanitize_text_field( wp_unslash( $this->input_server( 'HTTP_X_REAL_IP' ) ) );
		}

		if ( ! $ip && ! empty( $this->input_server( 'REMOTE_ADDR' ) ) ) {
			$ip = sanitize_text_field( wp_unslash( $this->input_server( 'REMOTE_ADDR' ) ) );
		}

		return $ip ?: '0.0.0.0';
	}

	/**
	 * Get the User-Agent header value.
	 *
	 * @return string
	 */
	private function get_user_agent() {
		return sanitize_text_field( wp_unslash( $this->input_server( 'HTTP_USER_AGENT' ) ) );
	}

	/**
	 * Resolve the honeypot field name for the given form.
	 *
	 * Uses the same logic as Crocina_Forms_Core::get_honeypot_name().
	 *
	 * @param int $form_id The crocina form ID.
	 * @return string
	 */
	private function get_honeypot_field_name( $form_id ) {
		if ( ! $form_id ) {
			return '';
		}
		return 'crocina_hp_' . md5( $form_id . NONCE_SALT );
	}
}
