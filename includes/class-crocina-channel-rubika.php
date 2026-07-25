<?php
/**
 * Rubika Notification Channel
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

class Crocina_Channel_Rubika implements Crocina_Notification_Channel {

	public function get_key() {
		return 'rubika';
	}

	public function get_label() {
		return __( 'Rubika', 'crocina-forms' );
	}

	public function send( $message, $config, $context ) {
		$token   = $config['token'] ?? '';
		$chat_id = $config['chat_id'] ?? '';
		$endpoint = $config['endpoint'] ?? '';

		if ( empty( $token ) || empty( $chat_id ) || empty( $endpoint ) ) {
			return false;
		}

		$message = $this->truncate( $message, 4000 );

		// SSRF hardening: check endpoint is allowed before sending.
		if ( class_exists( 'Crocina_Notifications' ) && ! Crocina_Notifications::is_endpoint_allowed( $endpoint, 'rubika' ) ) {
			$this->log_error( 'Rubika endpoint not allowed.', array( 'form_id' => $context['form_id'] ?? 0 ) );
			return false;
		}

		$body_data = array(
			'message' => $message,
			'chat_id' => $chat_id,
			'token'   => $token,
		);

		$body_data = apply_filters( 'crocina_notification_payload', $body_data, 'rubika', null, $context['payload'] ?? array(), $context['replacements'] ?? array(), array(), $message, $endpoint );
		$body_data = apply_filters( 'crocina_notification_payload_rubika', $body_data, null, $context['payload'] ?? array(), $context['replacements'] ?? array(), array(), $message, $endpoint );

		$body = wp_json_encode( $body_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return false;
		}

		$response = wp_safe_remote_post( esc_url_raw( $endpoint ), array(
			'method'      => 'POST',
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => $body,
			'timeout'     => 10,
			'redirection' => 0,
		) );

		$success = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) < 400;
		if ( ! $success ) {
			$this->log_error( 'Failed to send Rubika notification.', array( 'form_id' => $context['form_id'] ?? 0 ) );
		}
		return $success;
	}

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
		error_log( 'Crocina Channel Rubika: ' . $message . $ctx );
	}
}
