<?php
/**
 * Plugin Name: Crocina Forms
 * Plugin URI:  https://example.com
 * Description: Lightweight contact forms for redirect-less notifications, modern spam protection, and transferable configs.
 * Version:     0.1.0
 * Author:      محمد قربانی
 * Text Domain: crocina-forms
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function crocina_forms_requirements_met() {
	if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
		add_action( 'admin_notices', function() {
			?>
			<div class="notice notice-error">
				<p>
					<?php
					printf(
						esc_html__( 'Crocina Forms requires PHP 7.4 or higher. Your current PHP version is %s. Please upgrade.', 'crocina-forms' ),
						esc_html( PHP_VERSION )
					);
					?>
				</p>
			</div>
			<?php
		} );
		return false;
	}

	global $wp_version;
	if ( version_compare( $wp_version, '5.6', '<' ) ) {
		add_action( 'admin_notices', function() {
			?>
			<div class="notice notice-error">
				<p>
					<?php
					printf(
						esc_html__( 'Crocina Forms requires WordPress 5.6 or higher. Your current version is %s. Please upgrade.', 'crocina-forms' ),
						esc_html( get_bloginfo( 'version' ) )
					);
					?>
				</p>
			</div>
			<?php
		} );
		return false;
	}

	return true;
}

if ( ! crocina_forms_requirements_met() ) {
	return;
}

define( 'CROCINA_FORMS_VERSION', '0.1.0' );
define( 'CROCINA_FORMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CROCINA_FORMS_URL', plugin_dir_url( __FILE__ ) );
define( 'CROCINA_FORMS_MENU_SLUG', 'crocina-forms-dashboard' );
define( 'CROCINA_FORMS_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Load Composer autoloader if available — enables third-party
 * dependencies installed via Composer, such as Ar-PHP for
 * Persian/Arabic text shaping in watermarks.
 */
$composer_autoload = CROCINA_FORMS_DIR . 'vendor/autoload.php';
if ( is_file( $composer_autoload ) ) {
	require_once $composer_autoload;
}

spl_autoload_register( function( $class ) {
	$prefix = 'Crocina_';
	if ( 0 !== strpos( $class, $prefix ) ) {
		return;
	}

	$includes_dir = realpath( CROCINA_FORMS_DIR . 'includes/' );
	$map = array(
		'Crocina_App'                   => 'class-crocina-app.php',
		'Crocina_Forms'                 => 'class-crocina-forms.php',
		'Crocina_Forms_Core'            => 'class-crocina-core.php',
		'Crocina_Admin'                 => 'class-crocina-admin.php',
		'Crocina_Admin_Meta_Boxes'      => 'class-crocina-admin-meta-boxes.php',
		'Crocina_Admin_Ajax_Handler'    => 'class-crocina-admin-ajax-handler.php',
		'Crocina_Admin_Page_Renderer'   => 'class-crocina-admin-page-renderer.php',
		'Crocina_Ajax'                  => 'class-crocina-ajax.php',
		'Crocina_Render'                => 'class-crocina-render.php',
		'Crocina_Notifications'         => 'class-crocina-notifications.php',
		'Crocina_Notification_Channel'  => 'interface-crocina-notification-channel.php',
		'Crocina_Logger_Interface'      => 'interface-crocina-logger.php',
		'Crocina_Channel_Email'         => 'class-crocina-channel-email.php',
		'Crocina_Channel_Telegram'      => 'class-crocina-channel-telegram.php',
		'Crocina_Channel_Eitaa'         => 'class-crocina-channel-eitaa.php',
		'Crocina_Channel_Bale'          => 'class-crocina-channel-bale.php',
		'Crocina_Channel_Rubika'        => 'class-crocina-channel-rubika.php',
		'Crocina_Channel_WhatsApp'      => 'class-crocina-channel-whatsapp.php',
		'Crocina_Channel_Webhook'       => 'class-crocina-channel-webhook.php',
		'Crocina_Channel_Registry'      => 'class-crocina-channel-registry.php',
		'Crocina_Logger'                => 'class-crocina-logger.php',
		'Crocina_Inbox_List_Table'      => 'class-crocina-inbox-table.php',
		'Crocina_Event'                 => 'class-crocina-event.php',
		'Crocina_Event_Dispatcher'      => 'class-crocina-event-dispatcher.php',
		'Crocina_Rest_Controller'       => 'class-crocina-rest-controller.php',
		'Crocina_ServiceProvider'       => 'class-crocina-service-provider.php',
		'Crocina_Watermark'             => 'class-crocina-watermark.php',
		'Crocina_Subscriber_Interface'  => 'interface-crocina-subscriber.php',
		'Crocina_Event_Logger'          => 'class-crocina-event-logger.php',
		'Crocina_Event_Webhook'         => 'class-crocina-event-webhook.php',
		'Crocina_Cache_Warmer'          => 'class-crocina-cache-warmer.php',
		'Crocina_CLI'                   => 'class-crocina-cli.php',
		'Crocina_Migration_Naming'       => 'class-crocina-migration-naming.php',
		'Crocina_Security_CLI'          => 'class-crocina-security-cli.php',
		'Crocina_Request_Logger'        => 'class-crocina-request-logger.php',
		'Crocina_Form_List_Table'       => 'class-crocina-form-list-table.php',
	);

	if ( isset( $map[ $class ] ) ) {
		$file = CROCINA_FORMS_DIR . 'includes/' . $map[ $class ];
	} else {
		$relative_class = substr( $class, strlen( $prefix ) );
		$file = CROCINA_FORMS_DIR . 'includes/class-crocina-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';
	}
	$real_file = $file ? realpath( $file ) : false;
	if ( ! $real_file || ! $includes_dir || 0 !== strpos( $real_file, $includes_dir ) ) {
		return;
	}
	if ( file_exists( $real_file ) ) {
		require_once $real_file;
	}
} );

register_activation_hook( __FILE__, function () {
	// Boot the DI container (autoloader is already registered).
	$app = Crocina_App::instance();
	$app->boot();
	$app->get( 'core' )->activate();
} );
register_deactivation_hook( __FILE__, function () {
	Crocina_App::instance()->get( 'core' )->deactivate();
} );

/**
 * Load plugin textdomain on the 'init' hook (priority 10),
 * after the locale is fully set up.
 *
 * Must NOT be called earlier because WordPress 6.7+ triggers a
 * _load_textdomain_just_in_time notice when text domains are loaded
 * before the 'init' action.
 */
add_action( 'init', function() {
	load_plugin_textdomain( 'crocina-forms', false, dirname( CROCINA_FORMS_BASENAME ) . '/languages' );
}, 10 );

add_action( 'plugins_loaded', function() {
	Crocina_Forms::init();
}, 5 );

// Load WP-CLI commands when running via WP-CLI.
// The class files have their own guard (returns early if not WP_CLI).
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once CROCINA_FORMS_DIR . 'includes/class-crocina-cli.php';
	require_once CROCINA_FORMS_DIR . 'includes/class-crocina-security-cli.php';
}









