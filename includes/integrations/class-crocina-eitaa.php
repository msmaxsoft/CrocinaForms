<?php
/**
 * Crocina Eitaa API Integration
 *
 * Handles communication with the Eitaa messaging platform through the
 * official eitaayar.ir bot API.
 *
 * IMPORTANT (API contract):
 * - Base URL: https://eitaayar.ir/api/{TOKEN}/{method}
 * - The eitaayar API accepts parameters as `application/x-www-form-urlencoded`
 *   (form fields), NOT as a JSON body. Sending JSON causes the request to be
 *   silently rejected, which is the main reason "messages are not delivered".
 * - Supported methods are `sendMessage` and `sendFile`. Telegram-only methods
 *   such as `getMe` / `getUpdates` are not part of the eitaayar bot API, so the
 *   chat_id must be supplied by the user (channel numeric id or @username).
 *
 * References:
 * - https://developer.eitaa.com/docs/Develop/SendMassage/
 * - https://hasan.is-a.dev/eitaapykit/
 * - https://pypi.org/project/eitaapy/
 * - https://github.com/bistcuite/eitaapykit
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

class Crocina_Eitaa_API {

	/**
	 * Bot token for authentication.
	 *
	 * @var string
	 */
	private $bot_token;

	/**
	 * Base URL for the Eitaa API (trailing slash included).
	 *
	 * @var string
	 */
	private $api_base = 'https://eitaayar.ir/api/';

	/**
	 * HTTP request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout = 15;

	/**
	 * Constructor.
	 *
	 * @param string $bot_token The Eitaa bot token.
	 */
	public function __construct( $bot_token ) {
		$this->bot_token = trim( (string) $bot_token );
	}

	/**
	 * Make an API request using form-urlencoded parameters.
	 *
	 * The eitaayar bot API expects standard form fields, so `$params` is passed
	 * to wp_remote_post() as an array (which WordPress serialises as
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
		// the common token characters used by Eitaa.
		$url = $this->api_base . rawurlencode( $this->bot_token ) . '/' . rawurlencode( $method );

		// SSRF hardening: route the fully-built URL through the shared endpoint
		// gate so it is subject to the same HTTPS-only, private-IP-blocking, and
		// domain-allowlist protection as the generic webhook sender.
		if ( class_exists( 'Crocina_Notifications' ) && ! Crocina_Notifications::is_endpoint_allowed( $url, 'eitaa' ) ) {
			return array(
				'ok'          => false,
				'description' => __( 'Endpoint not allowed.', 'crocina-forms' ),
			);
		}

		$response = wp_remote_post(
			$url,
			array(
				// Passing an array makes WordPress send form-urlencoded data,
				// which is exactly what the eitaayar API requires.
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
	 * @param int|string $chat_id The channel numeric ID or @username.
	 * @param string     $text    The message text to send.
	 * @param array      $args    Optional extra parameters (title, pin, disable_notification, ...).
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

		if ( '' === $text ) {
			return array(
				'ok'          => false,
				'description' => __( 'Message text is empty.', 'crocina-forms' ),
			);
		}

		$params = array(
			'chat_id' => $chat_id,
			'text'    => $text,
		);

		// Optional whitelisted parameters supported by the eitaayar sendMessage API.
		$allowed = array( 'title', 'disable_notification', 'pin', 'reply_to_message_id', 'date', 'viewCountForDelete' );
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
