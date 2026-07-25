<?php
/**
 * Notification Channel Registry
 *
 * Maintains a map of all available notification channels and provides
 * a factory-like interface for the dispatcher to retrieve channel
 * instances by their machine key.
 *
 * Third-party plugins can register additional channels via the
 * `crocina_channel_registry` filter.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

final class Crocina_Channel_Registry {

	/**
	 * Registered channel instances, keyed by channel key.
	 *
	 * @var array<string, Crocina_Notification_Channel>
	 */
	private static $channels = array();

	/**
	 * Whether the built-in channels have been registered.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Lazily register built-in channels and apply the filter.
	 *
	 * @return void
	 */
	private static function ensure_init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		$built_in = array(
			new Crocina_Channel_Email(),
			new Crocina_Channel_Telegram(),
			new Crocina_Channel_Eitaa(),
			new Crocina_Channel_Bale(),
			new Crocina_Channel_Rubika(),
			new Crocina_Channel_WhatsApp(),
			new Crocina_Channel_Webhook(),
		);

		$built_in = apply_filters( 'crocina_channel_registry', $built_in );

		foreach ( $built_in as $channel ) {
			if ( $channel instanceof Crocina_Notification_Channel ) {
				self::$channels[ $channel->get_key() ] = $channel;
			}
		}
	}

	/**
	 * Get a channel instance by its machine key.
	 *
	 * @param string $key Channel key (e.g. 'telegram', 'eitaa').
	 * @return Crocina_Notification_Channel|null
	 */
	public static function get( $key ) {
		self::ensure_init();
		return isset( self::$channels[ $key ] ) ? self::$channels[ $key ] : null;
	}

	/**
	 * Return all registered channels.
	 *
	 * @return array<string, Crocina_Notification_Channel>
	 */
	public static function all() {
		self::ensure_init();
		return self::$channels;
	}

	/**
	 * Return only channels that are enabled for a given form.
	 *
	 * Reads the per-form alert options and checks which channels have
	 * their enable_{key} flag set.
	 *
	 * @param int   $form_id The form ID.
	 * @param array $options Pre-loaded alert options (optional).
	 * @return array<string, Crocina_Notification_Channel>
	 */
	public static function get_enabled_for_form( $form_id, $options = null ) {
		if ( null === $options ) {
			$options = get_post_meta( $form_id, 'crocina_alert_options', true );
		}
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$enabled = array();

		// Special handling for webhook: endpoints are listed line-by-line.
		$webhook_endpoints = self::parse_multiline_text( $options['webhook_endpoints'] ?? '' );
		foreach ( $webhook_endpoints as $endpoint ) {
			$channel = self::get( 'webhook' );
			if ( $channel ) {
				// We still return only one webhook channel, but the dispatcher
				// iterates the endpoints separately.
				$enabled['webhook'] = $channel;
			}
		}

		foreach ( self::all() as $key => $channel ) {
			if ( 'webhook' === $key ) {
				continue; // handled above
			}
			if ( ! empty( $options[ 'enable_' . $key ] ) ) {
				$enabled[ $key ] = $channel;
			}
		}

		return $enabled;
	}

	/**
	 * Parse newline-separated text into an array.
	 *
	 * @param string $text
	 * @return string[]
	 */
	private static function parse_multiline_text( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		$lines = array_map( 'trim', $lines );
		return array_values( array_filter( $lines, function( $line ) {
			return '' !== $line;
		} ) );
	}
}
