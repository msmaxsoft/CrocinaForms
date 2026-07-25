<?php
/**
 * Generic Webhook Notification Channel
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

class Crocina_Channel_Webhook implements Crocina_Notification_Channel {

	public function get_key() {
		return 'webhook';
	}

	public function get_label() {
		return __( 'Webhook', 'crocina-forms' );
	}

	public function send( $message, $config, $context ) {
		$endpoint = $config['endpoint'] ?? '';
		if ( empty( $endpoint ) ) {
			return false;
		}

		$payload    = $context['payload'] ?? array();
		$replacements = $context['replacements'] ?? array();
		$form       = $context['form'] ?? null;

		$body_data = array(
			'message'            => $message,
			'form_id'            => $payload['form_id'] ?? 0,
			'fields'             => $payload['fields'] ?? array(),
			'page_title'         => $payload['page_title'] ?? '',
			'page_url'           => $payload['page_url'] ?? '',
			'submitted_at'       => $payload['submitted_at'] ?? '',
			'submitted_at_jalali' => $payload['submitted_at_jalali'] ?? '',
			'user_ip'            => $payload['user_ip'] ?? '',
			'placeholders'       => $replacements,
		);

		$body_data = apply_filters( 'crocina_notification_payload', $body_data, 'webhook', $form, $payload, $replacements, array(), $message, $endpoint );
		$body_data = apply_filters( 'crocina_notification_payload_webhook', $body_data, $form, $payload, $replacements, array(), $message, $endpoint );

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
			$code      = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			$error_msg = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_message( $response );
			$this->log_error( "Failed to send webhook notification: {$error_msg}", array(
				'statusCode' => $code,
				'form_id'    => $payload['form_id'] ?? 0,
			) );
		}
		return $success;
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
		error_log( 'Crocina Channel Webhook: ' . $message . $ctx );
	}
}
