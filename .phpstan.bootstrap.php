<?php
/**
 * PHPStan bootstrap — defines WordPress constants that the plugin expects
 * to be set at runtime but are absent during static analysis.
 *
 * @package Crocina_Forms
 */

define( 'ABSPATH', __DIR__ . '/../../../' );
define( 'WP_DEBUG', false );
define( 'WP_CONTENT_DIR', __DIR__ . '/../../../wp-content' );
define( 'WP_PLUGIN_DIR', __DIR__ . '/../../..' );
define( 'WPINC', 'wp-includes' );

// The autoloader and plugin bootstrap these dynamically; mock them for analysis.
if ( ! defined( 'CROCINA_FORMS_VERSION' ) ) {
	define( 'CROCINA_FORMS_VERSION', '0.1.0' );
}
if ( ! defined( 'CROCINA_FORMS_DIR' ) ) {
	define( 'CROCINA_FORMS_DIR', __DIR__ . '/' );
}
if ( ! defined( 'CROCINA_FORMS_URL' ) ) {
	define( 'CROCINA_FORMS_URL', 'https://example.com/wp-content/plugins/crocina-forms/' );
}
if ( ! defined( 'CROCINA_FORMS_MENU_SLUG' ) ) {
	define( 'CROCINA_FORMS_MENU_SLUG', 'crocina-forms-dashboard' );
}
if ( ! defined( 'CROCINA_FORMS_BASENAME' ) ) {
	define( 'CROCINA_FORMS_BASENAME', 'crocina-forms/crocina-forms.php' );
}
if ( ! defined( 'NONCE_SALT' ) ) {
	define( 'NONCE_SALT', 'phpstan-static-analysis-nonce-salt' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
