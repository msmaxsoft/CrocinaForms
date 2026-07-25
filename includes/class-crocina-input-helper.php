<?php
/**
 * Crocina Input Helper — centralized null-safe superglobal accessors.
 *
 * This trait provides protected methods that read from $_GET, $_POST,
 * $_REQUEST, and $_SERVER with a safe default fallback (never null),
 * preventing PHP 8.1+ "ltrim(): Passing null to parameter #1 ($string)"
 * deprecation warnings when the result is passed to sanitization
 * functions such as sanitize_text_field(), esc_url_raw(), etc.
 *
 * Usage:
 * <code>
 * class Crocina_My_Class {
 *     use Crocina_Input_Helper;
 *     // Now $this->input_post( 'key' ) is available.
 * }
 * </code>
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

trait Crocina_Input_Helper {

	/* ------------------------------------------------------------------ */
	/*  Single-key accessors                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Safely read a value from $_GET.
	 *
	 * @param string $key     Array key.
	 * @param mixed  $default Fallback when key is not set (default '').
	 * @return mixed
	 */
	private function input_get( $key, $default = '' ) {
		return $_GET[ $key ] ?? $default; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Safely read a value from $_POST.
	 *
	 * @param string $key     Array key.
	 * @param mixed  $default Fallback when key is not set (default '').
	 * @return mixed
	 */
	private function input_post( $key, $default = '' ) {
		return $_POST[ $key ] ?? $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Safely read a value from $_REQUEST.
	 *
	 * @param string $key     Array key.
	 * @param mixed  $default Fallback when key is not set (default '').
	 * @return mixed
	 */
	private function input_request( $key, $default = '' ) {
		return $_REQUEST[ $key ] ?? $default; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Safely read a value from $_SERVER.
	 *
	 * @param string $key     Array key.
	 * @param mixed  $default Fallback when key is not set (default '').
	 * @return mixed
	 */
	private function input_server( $key, $default = '' ) {
		return $_SERVER[ $key ] ?? $default;
	}

	/* ------------------------------------------------------------------ */
	/*  Existence checks                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Check whether a key exists in $_POST.
	 *
	 * @param string $key Array key.
	 * @return bool
	 */
	private function has_post( $key ) {
		return isset( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Check whether a key exists in $_GET.
	 *
	 * @param string $key Array key.
	 * @return bool
	 */
	private function has_get( $key ) {
		return isset( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/* ------------------------------------------------------------------ */
	/*  Full-array accessors (for passing to sanitize methods)             */
	/* ------------------------------------------------------------------ */

	/**
	 * Return the raw $_POST array safely.
	 *
	 * Useful when a method expects the entire POST payload (e.g.
	 * $meta_boxes->sanitize_form_fields( $_POST )).  The method MUST
	 * handle missing keys internally with ?? or isset() guards.
	 *
	 * @return array
	 */
	private function get_post_array() {
		return $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Return the raw $_GET array safely.
	 *
	 * @return array
	 */
	private function get_get_array() {
		return $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Check whether a nonce parameter is present in $_REQUEST.
	 *
	 * Looks for common nonce field names used by WordPress and Crocina Forms.
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
}
