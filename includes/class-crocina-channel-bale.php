<?php
/**
 * Bale Notification Channel
 *
 * Wraps the existing Crocina_Bale_API integration class behind the
 * unified Notification_Channel interface.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

class Crocina_Channel_Bale implements Crocina_Notification_Channel {

	public function get_key() {
		return 'bale';
	}

	public function get_label() {
		return __( 'Bale', 'crocina-forms' );
	}

	public function send( $message, $config, $context ) {
		$token   = $config['token'] ?? '';
		$chat_id = $config['chat_id'] ?? '';
		if ( empty( $token ) || empty( $chat_id ) ) {
			return false;
		}

		$api_file = CROCINA_FORMS_DIR . 'includes/integrations/class-crocina-bale.php';
		if ( ! file_exists( $api_file ) ) {
			$this->log_error( 'Bale API class file not found.', array( 'form_id' => $context['form_id'] ?? 0 ) );
			return false;
		}
		require_once $api_file;

		$api_base = $config['endpoint'] ?? '';
		$bale     = new Crocina_Bale_API( $token, $api_base );

		$message = $this->truncate( $message, 4000 );
		$args    = array();
		$title   = $context['form'] ? $context['form']->post_title : '';
		if ( '' !== $title ) {
			$args['title'] = $title;
		}

		$result = $bale->send_message( $chat_id, $message, $args );

		if ( empty( $result['ok'] ) ) {
			$this->log_error(
				'Bale message failed: ' . ( $result['description'] ?? 'Unknown error' ),
				array( 'form_id' => $context['form_id'] ?? 0 )
			);
			return false;
		}
		return true;
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
		error_log( 'Crocina Channel Bale: ' . $message . $ctx );
	}
}
