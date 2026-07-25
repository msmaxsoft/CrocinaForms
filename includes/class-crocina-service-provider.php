<?php
/**
 * Crocina Service Provider
 *
 * Holds all factory methods previously defined inside Crocina_App.
 * Extracting them into a separate class keeps the container lean and
 * makes it easy to add/replace services without modifying the container.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

final class Crocina_ServiceProvider {

	/**
	 * Application container.
	 *
	 * @var Crocina_App
	 */
	private $app;

	/**
	 * @param Crocina_App $app
	 */
	public function __construct( Crocina_App $app ) {
		$this->app = $app;
	}

	/**
	 * Register all service factories into the container.
	 *
	 * @return void
	 */
	public function register() {
		$this->app->register( 'app', function () { return $this->app; } );
		$this->app->register( 'events',            array( $this, 'create_event_dispatcher' ) );
		$this->app->register( 'logger',            array( $this, 'create_logger' ) );
		$this->app->register( 'render',            array( $this, 'create_render' ) );
		$this->app->register( 'core',              array( $this, 'create_core' ) );
		$this->app->register( 'notifications',     array( $this, 'create_notifications' ) );
		$this->app->register( 'ajax',              array( $this, 'create_ajax' ) );
		$this->app->register( 'admin',             array( $this, 'create_admin' ) );
		$this->app->register( 'admin_meta_boxes',  array( $this, 'create_admin_meta_boxes' ) );
		$this->app->register( 'admin_ajax_handler', array( $this, 'create_admin_ajax_handler' ) );
		$this->app->register( 'admin_page_renderer', array( $this, 'create_admin_page_renderer' ) );
		$this->app->register( 'request_logger',     array( $this, 'create_request_logger' ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Factory methods                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * @return Crocina_Event_Dispatcher
	 */
	public function create_event_dispatcher() {
		$dispatcher = new Crocina_Event_Dispatcher();

		// Register the event logger subscriber (reads settings from core).
		$core = $this->app->get( 'core' );
		if ( $core ) {
			$settings   = $core->get_global_settings();
			$evt_logger = new Crocina_Event_Logger( $settings );
			$evt_logger->register( $dispatcher );

			// Register the event webhook subscriber.
			if ( class_exists( 'Crocina_Event_Webhook' ) ) {
				$evt_webhook = new Crocina_Event_Webhook( $settings );
				$dispatcher->register_subscriber( $evt_webhook );
			}

			// Register the cache warmer — rebuilds object caches after submission.
			if ( class_exists( 'Crocina_Cache_Warmer' ) ) {
				$cache_warmer = new Crocina_Cache_Warmer( $this->app );
				$dispatcher->register_subscriber( $cache_warmer );
			}
		}

		return $dispatcher;
	}

	/**
	 * @return Crocina_Logger
	 */
	public function create_logger() {
		$service = new Crocina_Logger( $this->app );
		$service->init();
		return $service;
	}

	/**
	 * @return Crocina_Render
	 */
	public function create_render() {
		$service = new Crocina_Render();
		$service->init();
		return $service;
	}

	/**
	 * @return Crocina_Forms_Core
	 */
	public function create_core() {
		$service = new Crocina_Forms_Core( $this->app );
		$service->init();
		return $service;
	}

	/**
	 * @return Crocina_Notifications
	 */
	public function create_notifications() {
		$service = new Crocina_Notifications( $this->app );
		$service->init();
		return $service;
	}

	/**
	 * @return Crocina_Ajax
	 */
	public function create_ajax() {
		$service = new Crocina_Ajax( $this->app );
		$service->init();
		return $service;
	}

	/**
	 * @return Crocina_Admin
	 */
	public function create_admin() {
		$service = new Crocina_Admin( $this->app );
		$service->init();
		return $service;
	}

	/**
	 * @return Crocina_Admin_Meta_Boxes
	 */
	public function create_admin_meta_boxes() {
		$service = new Crocina_Admin_Meta_Boxes( $this->app );
		$service->init();
		return $service;
	}

	/**
	 * @return Crocina_Admin_Ajax_Handler
	 */
	public function create_admin_ajax_handler() {
		$service = new Crocina_Admin_Ajax_Handler( $this->app );
		$service->init();
		return $service;
	}

	/**
	 * @return Crocina_Request_Logger
	 */
	public function create_request_logger() {
		$core = $this->app->get( 'core' );
		$settings = $core ? $core->get_global_settings() : array();
		$service = new Crocina_Request_Logger( $settings );
		$service->init();
		return $service;
	}

	/**
	 * @return Crocina_Admin_Page_Renderer
	 */
	public function create_admin_page_renderer() {
		$service = new Crocina_Admin_Page_Renderer( $this->app );
		$service->init();
		return $service;
	}
}
