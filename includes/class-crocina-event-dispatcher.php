<?php
/**
 * Event Dispatcher — lightweight PSR-14-style event system.
 *
 * Listeners are registered per event name. Dispatching invokes listeners
 * in priority order and, for backward compatibility, also fires the
 * equivalent WordPress do_action() so existing hook-based code continues
 * to work without changes.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

final class Crocina_Event_Dispatcher {

	/**
	 * Registered listeners, grouped by event name.
	 *
	 * @var array<string, array<int, list<callable>>>
	 */
	private $listeners = array();

	/**
	 * Whether to also fire WordPress do_action() on dispatch.
	 *
	 * @var bool
	 */
	private $wp_hook_bridge = true;

	/**
	 * Enable or disable the WordPress hook bridge.
	 *
	 * @param bool $enabled
	 * @return void
	 */
	public function set_wp_bridge( $enabled ) {
		$this->wp_hook_bridge = (bool) $enabled;
	}

	/**
	 * Register a listener for an event.
	 *
	 * @param string   $event_name Event name (e.g. 'form.submitted').
	 * @param callable $listener   Callback receives Crocina_Event as its only argument.
	 * @param int      $priority   Higher = runs later. Default 10.
	 * @return void
	 */
	public function add_listener( $event_name, $listener, $priority = 10 ) {
		if ( ! isset( $this->listeners[ $event_name ] ) ) {
			$this->listeners[ $event_name ] = array();
		}
		if ( ! isset( $this->listeners[ $event_name ][ $priority ] ) ) {
			$this->listeners[ $event_name ][ $priority ] = array();
		}
		$this->listeners[ $event_name ][ $priority ][] = $listener;
	}

	/**
	 * Remove a previously registered listener.
	 *
	 * @param string   $event_name
	 * @param callable $listener
	 * @return void
	 */
	public function remove_listener( $event_name, $listener ) {
		if ( empty( $this->listeners[ $event_name ] ) ) {
			return;
		}
		foreach ( $this->listeners[ $event_name ] as $priority => &$listeners_at ) {
			foreach ( $listeners_at as $i => $cb ) {
				if ( $cb === $listener ) {
					array_splice( $listeners_at, $i, 1 );
					if ( empty( $listeners_at ) ) {
						unset( $this->listeners[ $event_name ][ $priority ] );
					}
					return;
				}
			}
		}
	}

	/**
	 * Dispatch an event to all registered listeners.
	 *
	 * Also fires the WordPress do_action() for backward compatibility
	 * unless the bridge is disabled.
	 *
	 * @param Crocina_Event $event
	 * @return Crocina_Event The event (possibly mutated by listeners).
	 */
	public function dispatch( Crocina_Event $event ) {
		$name = $event->get_name();

		// Fire listeners in ascending priority order.
		if ( isset( $this->listeners[ $name ] ) ) {
			ksort( $this->listeners[ $name ] );
			foreach ( $this->listeners[ $name ] as $priority => $listeners_at ) {
				foreach ( $listeners_at as $listener ) {
					call_user_func( $listener, $event );
					if ( $event->is_propagation_stopped() ) {
						break 2;
					}
				}
			}
		}

		// Fire the WordPress action bridge for BC.
		// Passes params as individual args, same signature as the original do_action().
		if ( $this->wp_hook_bridge ) {
			do_action_ref_array( $name, array_values( $event->get_params() ) );
		}

		return $event;
	}

	/**
	 * Register a subscriber — automatically wire all its event listeners.
	 *
	 * Reads the mapping returned by get_subscribed_events() and calls
	 * add_listener() for each entry.
	 *
	 * @param Crocina_Subscriber_Interface $subscriber
	 * @return void
	 */
	public function register_subscriber( Crocina_Subscriber_Interface $subscriber ) {
		foreach ( $subscriber->get_subscribed_events() as $event_name => $config ) {
			if ( is_array( $config ) && isset( $config[1] ) ) {
				$method   = $config[0];
				$priority = $config[1];
			} else {
				$method   = $config;
				$priority = 10;
			}
			$this->add_listener( $event_name, array( $subscriber, $method ), $priority );
		}
	}

	/**
	 * Remove all listeners (optionally for a single event).
	 *
	 * @param string|null $event_name
	 * @return void
	 */
	public function clear_listeners( $event_name = null ) {
		if ( null === $event_name ) {
			$this->listeners = array();
		} else {
			unset( $this->listeners[ $event_name ] );
		}
	}
}
