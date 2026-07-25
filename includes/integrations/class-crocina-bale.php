<?php
/**
 * Crocina Bale API Integration
 *
 * Handles communication with the Bale messaging platform through its
 * Telegram-compatible bot API (tapi.bale.ai).
 *
 * IMPORTANT (API contract):
 * - Base URL: https://tapi.bale.ai/bot{TOKEN}/{method}
 * - Bale's bot API accepts parameters as `application/x-www-form-urlencoded`
 *   (form fields). Passing the parameters to wp_remote_post() as an array makes
 *   WordPress serialise them exactly that way. Sending a large custom JSON blob
 *   (message/fields/token/...) is silently rejected, which is the main reason
 *   "Bale messages are not delivered".
 * - The relevant method here is `sendMessage`, which expects `chat_id` + `text`.
 *   Unlike Eitaayar, Bale has no separate `title` parameter, so the title is
 *   merged into the beginning of the text.
 *
 * Reference implementation:
 * - https://github.com/hamidarab/wp-eitaa (class/EitaaAPI.php)
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

class Crocina_Bale_API {

	/**
	 * Bot token for authentication.
	 *
	 * @var string
	 */
	private $bot_token;

	/**
	 * Base URL for the Bale API (no trailing slash).
	 *
	 * @var string
	 */
	private $api_base = 'https://tapi.bale.ai';

	/**
	 * HTTP request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout = 15;

	/**
	 * Constructor.
	 *
	 * @param string $bot_token The Bale bot token.
	 * @param string $api_base  Optional base URL override (e.g. https://tapi.bale.ai).
	 */
	public function __construct( $bot_token, $api_base = '' ) {
		$this->bot_token = trim( (string) $bot_token );

		// Accept an optional base URL override, but normalise it down to just
		// scheme://host so a full sendMessage endpoint pasted into settings does
		// not cause the /bot{token}/{method} path to be appended twice.
		$api_base = trim( (string) $api_base );
		if ( '' !== $api_base && filter_var( $api_base, FILTER_VALIDATE_URL ) ) {
			$scheme = wp_parse_url( $api_base, PHP_URL_SCHEME );
			$host   = wp_parse_url( $api_base, PHP_URL_HOST );
			if ( $scheme && $host ) {
				$this->api_base = $scheme . '://' . $host;
			}
		}
	}


	/**
	 * Make an API request using form-urlencoded parameters.
	 *
	 * The Bale bot API expects standard form fields, so `$params` is passed to
	 * wp_remote_post() as an array (which WordPress serialises as
	 * application/x-www-form-urlencoded). Do NOT switch this to a JSON body.
	 *
	 * @param string $method The API method name (e.g., 'sendMessage').
	 * @param array  $params Optional parameters to send.
	 * @return array Response with 'ok' key and either 'result' or 'description'.
	 */
	private function request( $method, $params = array() ) {
		if ( empty( $this->bot_token ) ) {
			return array(
				'ok'          => false,
				'description' => __( 'Bot token is empty.', 'crocina-forms' ),
			);
		}

		// The token is part of the path; encode it defensively without mangling
		// the common token characters used by Bale.
		$url = $this->api_base . '/bot' . rawurlencode( $this->bot_token ) . '/' . rawurlencode( $method );

		// SSRF hardening: the api_base may be overridden from global settings, so
		// route the fully-built URL through the shared endpoint gate to enforce
		// the same HTTPS-only, private-IP-blocking, and domain-allowlist checks
		// used by the generic webhook sender.
		if ( class_exists( 'Crocina_Notifications' ) && ! Crocina_Notifications::is_endpoint_allowed( $url, 'bale' ) ) {
			return array(
				'ok'          => false,
				'description' => __( 'Endpoint not allowed.', 'crocina-forms' ),
			);
		}

		$response = wp_remote_post(
			$url,
			array(
				// Passing an array makes WordPress send form-urlencoded data,
				// which is exactly what the Bale API requires.
				'body'      => $params,
				'timeout'   => $this->timeout,
				'sslverify' => true,
				'headers'   => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'          => false,
				'description' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		if ( ! is_array( $body ) ) {
			return array(
				'ok'          => false,
				'description' => sprintf(
					/* translators: 1: HTTP status code, 2: raw response snippet. */
					__( 'Invalid response (HTTP %1$d): %2$s', 'crocina-forms' ),
					$code,
					mb_substr( wp_strip_all_tags( (string) $raw ), 0, 200 )
				),
			);
		}

		// Normalise: some error payloads omit `ok` but include description.
		if ( ! array_key_exists( 'ok', $body ) ) {
			$body['ok'] = ( $code >= 200 && $code < 300 );
		}

		if ( empty( $body['ok'] ) && empty( $body['description'] ) ) {
			$body['description'] = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Request failed (HTTP %d).', 'crocina-forms' ),
				$code
			);
		}

		return $body;
	}

	/**
	 * Send a text message to a chat/channel.
	 *
	 * @param int|string $chat_id The numeric chat ID or @username.
	 * @param string     $text    The message text to send.
	 * @param array      $args    Optional extra parameters (title merged into text, ...).
	 * @return array Response from the API.
	 */
	public function send_message( $chat_id, $text, $args = array() ) {
		$chat_id = trim( sanitize_text_field( (string) $chat_id ) );
		$text    = sanitize_textarea_field( (string) $text );

		if ( '' === $chat_id ) {
			return array(
				'ok'          => false,
				'description' => __( 'Chat ID is empty.', 'crocina-forms' ),
			);
		}

		// Bale has no dedicated title parameter; prepend it to the text like the
		// reference implementation does.
		$title = isset( $args['title'] ) ? trim( sanitize_text_field( (string) $args['title'] ) ) : '';
		if ( '' !== $title ) {
			$text = "🧾 {$title}\n\n" . $text;
		}

		if ( '' === trim( $text ) ) {
			return array(
				'ok'          => false,
				'description' => __( 'Message text is empty.', 'crocina-forms' ),
			);
		}

		$params = array(
			'chat_id' => $chat_id,
			'text'    => $text,
		);

		// Optional whitelisted parameters supported by the Bale sendMessage API.
		$allowed = array( 'reply_to_message_id', 'disable_notification', 'parse_mode' );
		foreach ( $allowed as $key ) {
			if ( isset( $args[ $key ] ) && '' !== $args[ $key ] ) {
				$params[ $key ] = $args[ $key ];
			}
		}

		return $this->request( 'sendMessage', $params );
	}

	/**
	 * Get the bot token (for internal use).
	 *
	 * @return string The bot token.
	 */
	public function get_token() {
		return $this->bot_token;
	}
}
