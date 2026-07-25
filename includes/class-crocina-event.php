<?php
/**
 * Event — immutable value object wrapping a named event with parameters.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

final class Crocina_Event {

	/**
	 * Event name (e.g. 'form.submitted').
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Arbitrary payload carried by the event.
	 *
	 * @var array
	 */
	private $params;

	/**
	 * Whether propagation has been stopped.
	 *
	 * @var bool
	 */
	private $propagation_stopped = false;

	/**
	 * @param string $name   Event name.
	 * @param array  $params Payload.
	 */
	public function __construct( $name, array $params = array() ) {
		$this->name   = $name;
		$this->params = $params;
	}

	/**
	 * Event name.
	 *
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Event payload.
	 *
	 * @return array
	 */
	public function get_params() {
		return $this->params;
	}

	/**
	 * Retrieve a single param by key.
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->params ) ? $this->params[ $key ] : $default;
	}

	/**
	 * Stop further listeners from receiving this event.
	 *
	 * @return void
	 */
	public function stop_propagation() {
		$this->propagation_stopped = true;
	}

	/**
	 * Whether propagation has been stopped.
	 *
	 * @return bool
	 */
	public function is_propagation_stopped() {
		return $this->propagation_stopped;
	}
}
