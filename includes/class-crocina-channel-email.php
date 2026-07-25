<?php
/**
 * Email Notification Channel
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

class Crocina_Channel_Email implements Crocina_Notification_Channel {

	public function get_key() {
		return 'email';
	}

	public function get_label() {
		return __( 'Email', 'crocina-forms' );
	}

	public function send( $message, $config, $context ) {
		$recipients = array_filter( array_map( 'sanitize_email', explode( ',', $config['recipients'] ?? '' ) ) );
		if ( empty( $recipients ) ) {
			$global = Crocina_Forms_Core::get_global_settings();
			$recipients = array_filter( array_map( 'sanitize_email', explode( ',', $global['admin_email'] ?? '' ) ) );
		}
		$recipients = array_slice( $recipients, 0, 20 );
		if ( empty( $recipients ) ) {
			return false;
		}

		$subject    = $config['subject'] ?? ( $context['subject'] ?? __( 'Form submission', 'crocina-forms' ) );
		$payload    = $context['payload'] ?? array();
		$attachments_paths = $context['attachments'] ?? array();

		$submitted_at_jalali = $payload['submitted_at_jalali'] ?? '';
		$meta_line = '';
		if ( $submitted_at_jalali ) {
			$meta_line = '<p><strong>' . esc_html__( 'Submitted at (Jalali):', 'crocina-forms' ) . '</strong> ' . esc_html( $submitted_at_jalali ) . '</p>';
		}
		$body  = '<html><body>' . wp_kses_post( $meta_line ) . '<pre>' . esc_html( $message ) . '</pre></body></html>';
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$sent = wp_mail( $recipients, wp_strip_all_tags( $subject ), $body, $headers, $attachments_paths );
		if ( ! $sent ) {
			$this->log_error( 'Email send failed.', array( 'form_id' => $context['form_id'] ?? 0 ) );
		}
		return $sent;
	}

	/**
	 * @param string $message
	 * @param array  $context
	 * @return void
	 */
	private function log_error( $message, $context = array() ) {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		$ctx = '';
		if ( ! empty( $context ) ) {
			$encoded = wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$ctx = false !== $encoded ? ' Context: ' . $encoded : '';
		}
		error_log( 'Crocina Channel Email: ' . $message . $ctx );
	}
}
