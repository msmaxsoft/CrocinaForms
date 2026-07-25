<?php
/**
 * Cache Warmer — rebuilds form_design and settings caches after a submission.
 *
 * When a form is submitted and processed, this subscriber fires and
 * pre-builds the WP Object Cache entries for the submitted form's design
 * and the global settings. This ensures the very next frontend visitor
 * (who may land on a page showing the same form) does not experience a
 * cold cache hit.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

final class Crocina_Cache_Warmer implements Crocina_Subscriber_Interface {

	/**
	 * Application container — used to resolve the core service.
	 *
	 * @var Crocina_App
	 */
	private $app;

	/**
	 * @param Crocina_App $app The application container.
	 */
	public function __construct( Crocina_App $app ) {
		$this->app = $app;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Subscribes to the 'after submission' event so the caches are
	 * rebuilt immediately after the form has been processed and before
	 * the response is sent to the browser.
	 *
	 * @return array<string, string|array{0:string, 1:int}>
	 */
	public function get_subscribed_events() {
		return array(
			// Low priority — run after all other listeners have finished.
			'crocina_form_after_submission' => array( 'on_form_after_submission', 999 ),
		);
	}

	/**
	 * Listener callback — rebuilds form_design and global settings caches.
	 *
	 * Extracts the form_id from the event params and calls the core
	 * service's cache-populating methods, which internally use
	 * wp_cache_set() to store the results in the WP Object Cache.
	 *
	 * @param Crocina_Event $event The dispatched event.
	 * @return void
	 */
	public function on_form_after_submission( Crocina_Event $event ) {
		$form_id = absint( $event->get( 'form_id', 0 ) );
		if ( ! $form_id ) {
			return;
		}

		/** @var Crocina_Forms_Core|null $core */
		$core = $this->app->resolve( 'core' );
		if ( ! $core ) {
			return;
		}

		// Calling these methods populates both the in-memory cache and the
		// persistent WP Object Cache (via wp_cache_set inside each method).
		$core->get_global_settings();
		$core->get_form_design( $form_id );
	}
}
