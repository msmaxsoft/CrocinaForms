<?php
/**
 * Crocina Application Container
 *
 * Central dependency-injection container that creates and wires every
 * plugin class.  Classes request their dependencies through the
 * constructor, and the container resolves them lazily.
 *
 * Hook registration happens inside each class's init() method so
 * WordPress hooks are registered on instances, not static references.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

class Crocina_App {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Resolved service instances.
	 *
	 * @var array<string, object>
	 */
	private $services = array();

	/**
	 * Factory callbacks for lazy services.
	 *
	 * @var array<string, callable>
	 */
	private $factories = array();

	/**
	 * Whether boot() has been called.
	 *
	 * @var bool
	 */
	private $booted = false;


	/* ------------------------------------------------------------------ */
	/*  Bootstrap                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Get or create the singleton application instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register factories (via service provider) and boot all services.
	 *
	 * Called once at plugin initialisation.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Delegate factory registration to the service provider.
		$provider = new Crocina_ServiceProvider( $this );
		$provider->register();

		// Instantiate and init all registered services eagerly.
		foreach ( array_keys( $this->factories ) as $id ) {
			$this->get( $id );
		}
	}


	/* ------------------------------------------------------------------ */
	/*  Service resolution                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Register a factory callback for a service.
	 *
	 * @param string   $id      Service identifier.
	 * @param callable $factory Factory that returns the service instance.
	 * @return void
	 */
	public function register( $id, $factory ) {
		$this->factories[ $id ] = $factory;
	}

	/**
	 * Retrieve a service instance.
	 *
	 * @param string $id Service identifier.
	 * @return object|null
	 */
	public function get( $id ) {
		if ( ! isset( $this->services[ $id ] ) ) {
			if ( ! isset( $this->factories[ $id ] ) ) {
				return null;
			}
			$this->services[ $id ] = call_user_func( $this->factories[ $id ] );
		}
		return $this->services[ $id ];
	}

	/**
	 * Shortcut: return a service by its class name.
	 *
	 * Convenient when you know the service identifier matches the
	 * short class basename in lowercase (e.g. 'logger' → Crocina_Logger).
	 *
	 * @param string $class_basename Short name (e.g. 'core', 'logger').
	 * @return object|null
	 */
	public function resolve( $class_basename ) {
		return $this->get( $class_basename );
	}


}
