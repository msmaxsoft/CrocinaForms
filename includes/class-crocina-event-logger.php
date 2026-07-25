<?php
/**
 * Event Logger — debugs dispatched events to a dedicated file.
 *
 * Writes a structured log entry for each dispatched event (form.submitted,
 * notification.sent, log.created, etc.) into a plugin-specific log file
 * inside the WordPress uploads directory.
 *
 * This works independently of WP_DEBUG, making it suitable for production
 * troubleshooting.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

final class Crocina_Event_Logger implements Crocina_Subscriber_Interface {

	/**
	 * Event names this logger subscribes to.
	 *
	 * @var string[]
	 */
	private $events = array(
		'crocina_form_submitted',
		'crocina_form_after_submission',
		'crocina_after_log_insert',
	);

	/**
	 * Whether event logging is enabled in settings.
	 *
	 * @var bool
	 */
	private $enabled = false;

	/**
	 * Full path to the log file.
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
	 * @param array $settings Global plugin settings (from get_global_settings()).
	 */
	public function __construct( array $settings = array() ) {
		$this->enabled = ! empty( $settings['event_logging_enabled'] );
		if ( $this->enabled ) {
			$this->init_log_file();
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_subscribed_events() {
		if ( ! $this->enabled ) {
			return array();
		}

		$events = array();
		foreach ( $this->events as $event_name ) {
			$events[ $event_name ] = array( 'on_event', 999 );
		}
		return $events;
	}

	/**
	 * Subscribe to all known events via the dispatcher.
	 *
	 * @param Crocina_Event_Dispatcher $dispatcher
	 * @return void
	 */
	public function register( Crocina_Event_Dispatcher $dispatcher ) {
		if ( ! $this->enabled ) {
			return;
		}

		// Prefer the subscriber interface; fallback to manual registration.
		$dispatcher->register_subscriber( $this );
	}

	/**
	 * Listener callback — fired for every subscribed event.
	 *
	 * @param Crocina_Event $event
	 * @return void
	 */
	public function on_event( Crocina_Event $event ) {
		if ( ! $this->enabled || ! $this->log_file ) {
			return;
		}

		$this->rotate_if_needed();

		$entry = $this->format_entry( $event );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $entry . PHP_EOL, 3, $this->log_file );
	}

	/* ------------------------------------------------------------------ */
	/*  Private helpers                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Create the log directory and determine the log file path.
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
	 * Keeps at most one backup (events.log.1).
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
	 * Build a human-readable log entry from the event.
	 *
	 * @param Crocina_Event $event
	 * @return string
	 */
	private function format_entry( Crocina_Event $event ) {
		$time    = gmdate( 'Y-m-d H:i:s' );
		$name    = $event->get_name();
		$params  = $event->get_params();

		// Sanitize params for safe logging — strip sensitive keys.
		$safe = $this->sanitize_for_log( $params );

		return sprintf(
			'[%s] %s %s',
			$time,
			str_pad( $name, 40 ),
			wp_json_encode( $safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
	}

	/**
	 * Remove sensitive data from event params before logging.
	 *
	 * @param array $params
	 * @return array
	 */
	private function sanitize_for_log( array $params ) {
		$sensitive_keys = array( 'user_ip', 'user_agent' );
		$result = array();

		foreach ( $params as $key => $value ) {
			if ( in_array( $key, $sensitive_keys, true ) ) {
				$result[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$result[ $key ] = $this->sanitize_for_log( $value );
			} elseif ( is_string( $value ) && strlen( $value ) > 500 ) {
				$result[ $key ] = substr( $value, 0, 500 ) . '... [truncated]';
			} else {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}
}
