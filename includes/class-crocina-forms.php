<?php

defined( 'ABSPATH' ) || exit;

final class Crocina_Forms {
	private static $instance = null;

	/**
	 * @var Crocina_App
	 */
	private $app;

	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		do_action( 'crocina_forms_before_init' );

		// Boot the DI container — wires and inits all services.
		$this->app = Crocina_App::instance();
		$this->app->boot();

		do_action( 'crocina_forms_after_init' );
	}

	private function __clone() {
	}

	public function __wakeup() {
	}
}
