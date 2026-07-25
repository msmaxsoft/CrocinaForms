<?php
/**
 * Telegram Notification Channel
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

class Crocina_Channel_Telegram implements Crocina_Notification_Channel {

	public function get_key() {
		return 'telegram';
	}

	public function get_label() {
		return __( 'Telegram', 'crocina-forms' );
	}

	public function send( $message, $config, $context ) {
		$token   = $config['token'] ?? '';
		$chat_id = $config['chat_id'] ?? '';
		if ( empty( $token ) || empty( $chat_id ) ) {
			return false;
		}

		$endpoint = ! empty( $config['endpoint'] )
			? $config['endpoint']
			: 'https://api.telegram.org/bot%s/sendMessage';

		$url = sprintf( $endpoint, $token );

		if ( ! Crocina_Notifications::is_endpoint_allowed( $url, 'telegram' ) ) {
			return false;
		}

		$telegram_message = $this->truncate( $this->escape_html( $message ), 4096 );

		$body_data = array(
			'chat_id'    => $chat_id,
			'text'       => $telegram_message,
			'parse_mode' => 'HTML',
		);

		// Apply the standard notification payload filters so third-party code
		// can modify Telegram messages just as it could with the old send_webhook.
		$body_data = apply_filters( 'crocina_notification_payload', $body_data, 'telegram', $context['form'] ?? null, $context['payload'] ?? array(), $context['replacements'] ?? array(), array(), $telegram_message, $url );
		$body_data = apply_filters( 'crocina_notification_payload_telegram', $body_data, $context['form'] ?? null, $context['payload'] ?? array(), $context['replacements'] ?? array(), array(), $telegram_message, $url );

		$body = wp_json_encode( $body_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $body ) {
			return false;
		}

		$response = wp_safe_remote_post( esc_url_raw( $url ), array(
			'method'      => 'POST',
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => $body,
			'timeout'     => 10,
			'redirection' => 0,
		) );

		$success = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 400;
		if ( ! $success ) {
			$this->log_error( 'Failed to send Telegram notification.', array(
				'form_id' => $context['form_id'] ?? 0,
			) );
		}
		return $success;
	}

	/**
	 * Escape text for Telegram HTML parse mode.
	 */
	private function escape_html( $text ) {
		return strtr( $text, array(
			'&' => '&amp;',
			'<' => '&lt;',
			'>' => '&gt;',
			'"' => '&quot;',
		) );
	}

	/**
	 * Truncate message to a maximum length.
	 */
	private function truncate( $message, $max_length ) {
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $message ) <= $max_length ) {
				return $message;
			}
			return mb_substr( $message, 0, max( 0, $max_length - 3 ) ) . '...';
		}
		if ( strlen( $message ) <= $max_length ) {
			return $message;
		}
		return substr( $message, 0, max( 0, $max_length - 3 ) ) . '...';
	}

	private function log_error( $message, $context = array() ) {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		$ctx = '';
		if ( ! empty( $context ) ) {
			$encoded = wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$ctx = false !== $encoded ? ' Context: ' . $encoded : '';
		}
		error_log( 'Crocina Channel Telegram: ' . $message . $ctx );
	}
}
