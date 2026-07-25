<?php
/**
 * Interface for event subscribers.
 *
 * Any class implementing this interface can be registered with the
 * Crocina_Event_Dispatcher via register_subscriber(). The dispatcher
 * will automatically subscribe the implementing class's listener
 * methods to the events returned by get_subscribed_events().
 *
 * Inspired by Symfony's EventSubscriberInterface.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

interface Crocina_Subscriber_Interface {

	/**
	 * Return an array of event-name → method-name mappings.
	 *
	 * The returned array uses event names as keys and method names
	 * (or arrays of [method_name, priority]) as values.
	 *
	 * Examples:
	 *
	 *     return array(
	 *         'crocina_form_submitted'       => 'on_form_submitted',
	 *         'crocina_after_log_insert'     => array( 'on_log_created', 20 ),
	 *         'crocina_form_after_submission' => array( 'on_after_submission', 5 ),
	 *     );
	 *
	 * Each method receives a single Crocina_Event argument.
	 *
	 * @return array<string, string|array{0:string, 1:int}>
	 */
	public function get_subscribed_events();
}
