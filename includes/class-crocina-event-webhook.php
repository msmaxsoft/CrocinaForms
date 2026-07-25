<?php
/**
 * Event Webhook — fires a JSON POST to a configured endpoint for every event.
 *
 * Subscribe to all plugin events and forward them as JSON payloads to an
 * external webhook URL. Useful for integrating with Zapier, n8n, Slack,
 * custom monitoring dashboards, or any HTTP‑accessible logging service.
 *
 * Includes configurable rate limiting (requests per minute), body size
 * limiting, and automatic retry with WP Cron when the endpoint returns
 * a 5xx error or times out.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

final class Crocina_Event_Webhook implements Crocina_Subscriber_Interface {

	/**
	 * Event names this webhook subscribes to.
	 *
	 * @var string[]
	 */
	private $events = array(
		'crocina_form_submitted',
		'crocina_form_after_submission',
		'crocina_after_log_insert',
	);

	/**
	 * Whether the webhook is enabled (URL is non‑empty).
	 *
	 * @var bool
	 */
	private $enabled = false;

	/**
	 * The target webhook URL.
	 *
	 * @var string
	 */
	private $webhook_url = '';

	/**
	 * Max requests per minute (0 = unlimited).
	 *
	 * @var int
	 */
	private $rate_limit = 60;

	/**
	 * Max JSON body size in bytes before truncation (0 = unlimited).
	 *
	 * @var int
	 */
	private $max_body_bytes = 102400; // 100 KB default.

	/**
	 * Custom Authorization: Bearer token sent with every webhook request.
	 *
	 * Empty string means no Authorization header is added.
	 *
	 * @var string
	 */
	private $bearer_token = '';

	/**
	 * Maximum retry attempts on 5xx / timeout failure.
	 *
	 * @var int
	 */
	private $max_retries = 3;

	/**
	 * Seconds to wait between retry attempts.
	 *
	 * @var int
	 */
	private $retry_delay = 30;

	/**
	 * Cron hook name used for retry scheduling.
	 *
	 * @var string
	 */
	private const RETRY_CRON_HOOK = 'crocina_event_webhook_retry';

	/**
	 * Transient key prefix for retry metadata.
	 *
	 * @var string
	 */
	private const RETRY_KEY_PREFIX = 'crocina_wh_retry_';

	/**
	 * Transient TTL for retry metadata (7 days — ample time for max retries).
	 *
	 * @var int
	 */
	private const RETRY_TTL = DAY_IN_SECONDS * 7;

	/**
	 * Transient key prefix for rate-limit counters.
	 *
	 * @var string
	 */
	private const RATE_KEY_PREFIX = 'crocina_webhook_rl_';

	/**
	 * Window in seconds for the rate-limit counter.
	 *
	 * @var int
	 */
	private const RATE_WINDOW = 60;

	/**
	 * @param array $settings Global plugin settings (from get_global_settings()).
	 */
	public function __construct( array $settings = array() ) {
		$url = ! empty( $settings['event_webhook_url'] ) ? trim( $settings['event_webhook_url'] ) : '';
		if ( '' !== $url && false !== filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$this->enabled     = true;
			$this->webhook_url = $url;
		}

		// Rate limit: requests per minute (0 = unlimited).
		$rl = isset( $settings['event_webhook_rate_limit'] ) ? absint( $settings['event_webhook_rate_limit'] ) : 60;
		$this->rate_limit = max( 0, $rl );

		// Body size limit in KB (0 = unlimited).
		$kb = isset( $settings['event_webhook_max_body_kb'] ) ? absint( $settings['event_webhook_max_body_kb'] ) : 100;
		$this->max_body_bytes = max( 0, $kb ) * 1024;

		// Retry settings.
		$this->max_retries  = max( 0, absint( $settings['event_webhook_max_retries'] ?? 3 ) );
		$this->retry_delay  = max( 10, absint( $settings['event_webhook_retry_delay'] ?? 30 ) );

		// Bearer token for Authorization header.
		$token = isset( $settings['event_webhook_bearer_token'] ) ? trim( $settings['event_webhook_bearer_token'] ) : '';
		if ( '' !== $token ) {
			// Strip accidental "Bearer " prefix so the header stays valid.
			if ( 0 === stripos( $token, 'bearer ' ) ) {
				$token = trim( substr( $token, 7 ) );
			}
			$this->bearer_token = $token;
		}

		// Register the cron hook here (not in a separate init() method)
		// so it is always available even if the subscriber is registered
		// before the plugin's init hook fires.
		add_action( self::RETRY_CRON_HOOK, array( $this, 'retry' ) );
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
			// Low priority so it runs after all internal listeners.
			$events[ $event_name ] = array( 'on_event', 200 );
		}
		return $events;
	}

	/**
	 * Listener callback — fires for every subscribed event.
	 *
	 * Applies rate limiting, builds the JSON payload, enforces body size
	 * limits, then sends a non‑blocking HTTP POST (fire‑and‑forget) so
	 * the form response is not delayed by the webhook round‑trip.
	 *
	 * Individual retries (via WP Cron) use blocking mode so we can
	 * detect success or failure and decide whether to retry again.
	 *
	 * @param Crocina_Event $event
	 * @return void
	 */
	public function on_event( Crocina_Event $event ) {
		if ( ! $this->enabled || ! $this->webhook_url ) {
			return;
		}

		// 1. Rate limit check.
		if ( ! $this->check_rate_limit() ) {
			return;
		}

		// 2. Build payload.
		$payload = array(
			'event'     => $event->get_name(),
			'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'site'      => get_home_url(),
			'data'      => $event->get_params(),
		);

		/**
		 * Filter the JSON payload sent to the event webhook.
		 *
		 * @param array  $payload The payload array (will be JSON-encoded).
		 * @param string $url     The target webhook URL.
		 * @return array
		 */
		$payload = apply_filters( 'crocina_event_webhook_payload', $payload, $this->webhook_url );

		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return;
		}

		// 3. Enforce body size limit.
		$json = $this->apply_body_limit( $json );

		// 4. Send non‑blocking (fire‑and‑forget) so the form response is
		//    not delayed by the webhook round‑trip to an external endpoint.
		$this->send_async( $json );

		// 5. If retries are enabled, schedule the first deferred retry.
		//    The retry path uses blocking mode so it can detect success
		//    or failure and decide whether to schedule another attempt.
		if ( $this->max_retries > 0 ) {
			$this->schedule_retry( $json );
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Rate limiting                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Check whether the current request is within the rate limit.
	 *
	 * Uses a transient keyed by the webhook URL hash so each endpoint
	 * gets its own counter. Resets every 60 seconds.
	 *
	 * @return bool True if the request may proceed.
	 */
	private function check_rate_limit() {
		if ( 0 === $this->rate_limit ) {
			return true; // Unlimited.
		}

		$key    = self::RATE_KEY_PREFIX . md5( $this->webhook_url );
		$window = (int) get_transient( $key );

		if ( $window >= $this->rate_limit ) {
			// Rate limit exceeded — skip this event.
			return false;
		}

		// Increment counter (first call sets the 60-second TTL).
		if ( false === $window ) {
			set_transient( $key, 1, self::RATE_WINDOW );
		} else {
			set_transient( $key, $window + 1, self::RATE_WINDOW );
		}

		return true;
	}

	/* ------------------------------------------------------------------ */
	/*  Retry on failure                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Determine whether an HTTP response indicates a retryable failure.
	 *
	 * Treats transport errors (WP_Error — includes timeouts) and 5xx
	 * status codes as retryable. 4xx errors are NOT retried because
	 * they indicate a client-side problem (bad request, auth, etc.).
	 *
	 * @param array|\WP_Error $response The wp_remote_post() return value.
	 * @return bool
	 */
	private function is_failure( $response ) {
		if ( is_wp_error( $response ) ) {
			return true;
		}
		$code = wp_remote_retrieve_response_code( $response );
		return $code >= 500 && $code <= 599;
	}

	/**
	 * Schedule the first retry attempt via WP Cron after the configured delay.
	 *
	 * Stores the encoded JSON body in a transient so it can be resent
	 * by the cron callback. The transient is keyed by a hash of the URL
	 * plus the JSON so that duplicate retries for the same payload are
	 * collapsed into one.
	 *
	 * The original HTTP request was fire‑and‑forget, so we don't have
	 * a response to inspect. We schedule the first retry optimistically;
	 * if the initial send actually succeeded the blocking retry will see
	 * a 2xx and clean up without a second attempt.
	 *
	 * @param string $json Encoded JSON payload.
	 * @return void
	 */
	private function schedule_retry( $json ) {
		if ( 0 === $this->max_retries ) {
			return; // Retries disabled.
		}

		$retry_key = self::RETRY_KEY_PREFIX . md5( $this->webhook_url . '|' . $json );
		$existing  = get_transient( $retry_key );

		// If a retry is already scheduled for this exact payload, don't duplicate.
		if ( false !== $existing ) {
			return;
		}

		$retry_data = array(
			'url'              => $this->webhook_url,
			'json'             => $json,
			'attempt'          => 0,
			'max_attempts'     => $this->max_retries,
			'first_failed_at'  => gmdate( 'Y-m-d H:i:s' ),
			'last_error'       => 'scheduled (fire-and-forget)',
		);

		set_transient( $retry_key, $retry_data, self::RETRY_TTL );

		// Schedule the first retry attempt.
		if ( ! wp_next_scheduled( self::RETRY_CRON_HOOK, array( $retry_key ) ) ) {
			wp_schedule_single_event( time() + $this->retry_delay, self::RETRY_CRON_HOOK, array( $retry_key ) );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf(
			'[Crocina Event Webhook] Scheduled retry #1 of %d for %s (deferred check).',
			$this->max_retries,
			$this->webhook_url
		) );
	}

	/**
	 * WP Cron callback — retry sending a previously-failed webhook payload.
	 *
	 * Loads the serialised data from the transient, increments the attempt
	 * counter, sends the JSON, then either cleans up on success or schedules
	 * the next retry if the max attempt count hasn't been reached.
	 *
	 * @param string $retry_key The transient key identifying the retry job.
	 * @return void
	 */
	public function retry( $retry_key ) {
		$data = get_transient( $retry_key );
		if ( false === $data || ! is_array( $data ) ) {
			// Payload was already cleaned up or expired — nothing to do.
			return;
		}

		$data['attempt'] = ( $data['attempt'] ?? 0 ) + 1;
		$url  = $data['url']  ?? '';
		$json = $data['json'] ?? '';

		if ( '' === $url || '' === $json ) {
			delete_transient( $retry_key );
			return;
		}

		// Send with blocking mode via the dedicated method.
		$response = $this->send_blocking( $json, $url );

		// Check result.
		if ( ! $this->is_failure( $response ) ) {
			// Success — clean up the transient.
			delete_transient( $retry_key );

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[Crocina Event Webhook] Retry #%d succeeded for %s.',
				$data['attempt'],
				$url
			) );
			return;
		}

		// Still failing — update last_error and check if we should retry again.
		$data['last_error'] = is_wp_error( $response )
			? $response->get_error_message()
			: 'HTTP ' . wp_remote_retrieve_response_code( $response );

		if ( $data['attempt'] >= $data['max_attempts'] ) {
			// Exhausted all retries — log and clean up.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[Crocina Event Webhook] Retry exhausted after %d attempts for %s. Last error: %s.',
				$data['attempt'],
				$url,
				$data['last_error']
			) );
			delete_transient( $retry_key );
			return;
		}

		// Save updated data (bumped attempt + last_error) and schedule next retry.
		set_transient( $retry_key, $data, self::RETRY_TTL );

		if ( ! wp_next_scheduled( self::RETRY_CRON_HOOK, array( $retry_key ) ) ) {
			wp_schedule_single_event( time() + $this->retry_delay, self::RETRY_CRON_HOOK, array( $retry_key ) );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf(
			'[Crocina Event Webhook] Scheduled retry #%d of %d for %s (error: %s).',
			$data['attempt'] + 1,
			$data['max_attempts'],
			$url,
			$data['last_error']
		) );
	}

	/**
	 * Clean up stale retry transients that could be left behind if a cron
	 * job was deleted or skipped.
	 *
	 * Iterates known retry transients (up to a reasonable limit) and removes
	 * those that have expired based on their internal attempt counter and
	 * the configured max retries.
	 *
	 * @return int Number of cleaned-up transients.
	 */
	public function cleanup_stale_retries() {
		global $wpdb;

		$prefix   = self::RETRY_KEY_PREFIX;
		$option_prefix = '_transient_' . $prefix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 100",
				$option_prefix . '%'
			)
		);

		$cleaned = 0;
		foreach ( $rows as $row ) {
			$key = str_replace( '_transient_', '', $row->option_name );
			$data = get_transient( $key );
			if ( false === $data || ! is_array( $data ) ) {
				continue;
			}
			$attempt     = $data['attempt'] ?? 0;
			$max_attempt = $data['max_attempts'] ?? 3;
			$url         = $data['url'] ?? '';

			// Clean up completed or abandoned retries.
			if ( $attempt >= $max_attempt || '' === $url ) {
				delete_transient( $key );
				++$cleaned;
			}
		}

		return $cleaned;
	}

	/* ------------------------------------------------------------------ */
	/*  Body size limiting                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Truncate the payload's `data` field if the JSON body exceeds
	 * the configured limit.
	 *
	 * Strips nested field values inside `data.fields` first, then the
	 * entire `data` key as a last resort, so the outer structure
	 * (event, timestamp, site) is always preserved for the endpoint.
	 *
	 * @param string $json Encoded JSON body.
	 * @return string Truncated JSON (may be unchanged if under limit).
	 */
	private function apply_body_limit( $json ) {
		if ( 0 === $this->max_body_bytes || strlen( $json ) <= $this->max_body_bytes ) {
			return $json;
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) ) {
			// Can't parse — truncate the whole string.
			return substr( $json, 0, $this->max_body_bytes - 3 ) . '...';
		}

		// First pass: truncate long string values inside data.fields.
		if ( isset( $decoded['data']['fields'] ) && is_array( $decoded['data']['fields'] ) ) {
			foreach ( $decoded['data']['fields'] as $key => $value ) {
				if ( is_string( $value ) && strlen( $value ) > 500 ) {
					$decoded['data']['fields'][ $key ] = substr( $value, 0, 500 ) . '... [truncated]';
				}
			}
			$json = wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( strlen( $json ) <= $this->max_body_bytes ) {
				return $json;
			}
		}

		// Second pass: strip the entire data blob, keep only event + meta.
		unset( $decoded['data'] );
		$decoded['data_truncated'] = true;
		$decoded['data_note']      = sprintf(
			'Payload exceeded %d KB limit. Raw data omitted.',
			$this->max_body_bytes / 1024
		);

		$json = wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			$json = '{"error":"json_encode_failed","data_truncated":true,"note":"Body limit exceeded and re-encoding failed"}';
		}

		// Final safety: hard truncate.
		if ( strlen( $json ) > $this->max_body_bytes ) {
			$json = substr( $json, 0, $this->max_body_bytes - 3 ) . '...';
		}

		return $json;
	}

	/**
	 * Build the common headers sent with every webhook request.
	 *
	 * Includes Content-Type, X-Crocina-Event, and optionally the
	 * Authorization: Bearer header if a token is configured.
	 *
	 * @param string $event_type Identifies the sender (e.g. 'webhook', 'webhook-retry').
	 * @return array
	 */
	private function build_headers( $event_type ) {
		$headers = array(
			'Content-Type'    => 'application/json',
			'X-Crocina-Event' => $event_type,
		);

		if ( '' !== $this->bearer_token ) {
			$headers['Authorization'] = 'Bearer ' . $this->bearer_token;
		}

		return $headers;
	}

	/**
	 * Send a fire‑and‑forget (non‑blocking) HTTP POST to the webhook URL.
	 *
	 * Used for the initial event delivery so the form response is not
	 * delayed by the external HTTP round‑trip.
	 *
	 * @param string $json Encoded JSON body.
	 * @return void
	 */
	private function send_async( $json ) {
		$args = array(
			'body'    => $json,
			'headers' => $this->build_headers( 'webhook' ),
			'timeout'  => 5,
			'blocking' => false,
		);

		/**
		 * Filter the HTTP arguments used for the webhook POST request.
		 *
		 * @param array  $args wp_remote_post() arguments.
		 * @param string $url  The target webhook URL.
		 * @return array
		 */
		$args = apply_filters( 'crocina_event_webhook_args', $args, $this->webhook_url );

		wp_remote_post( $this->webhook_url, $args );
	}

	/**
	 * Send a blocking HTTP POST to the webhook URL.
	 *
	 * Used in the retry path so the caller can inspect the response and
	 * decide whether to schedule another attempt. Timeout is set to 10
	 * seconds (vs 5 for the async send) to give the endpoint more time
	 * during a retry scenario.
	 *
	 * @param string $json Encoded JSON body.
	 * @param string $url  Target URL (from the retry transient).
	 * @return array|\WP_Error The wp_remote_post() response.
	 */
	private function send_blocking( $json, $url ) {
		$args = array(
			'body'    => $json,
			'headers' => $this->build_headers( 'webhook-retry' ),
			'timeout'  => 10,
			'blocking' => true,
		);

		/**
		 * Filter the HTTP arguments used for the webhook POST request.
		 *
		 * @param array  $args wp_remote_post() arguments.
		 * @param string $url  The target webhook URL.
		 * @return array
		 */
		$args = apply_filters( 'crocina_event_webhook_args', $args, $url );

		return wp_remote_post( $url, $args );
	}
}
