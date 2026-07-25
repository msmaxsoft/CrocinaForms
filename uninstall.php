<?php
/**
 * Crocina Forms uninstall routine.
 *
 * Removes all plugin data (options, transients, custom table, post meta, and
 * the crocina_form posts) when the plugin is deleted from the WordPress admin.
 *
 * @package Crocina_Forms
 */

// Exit if uninstall is not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete all plugin data for a single site.
 */
function crocina_forms_uninstall_site() {
	global $wpdb;

	// 1. Delete plugin options.
	delete_option( 'crocina_forms_settings' );
	delete_option( 'crocina_logs_db_version' );

	// 2. Drop the custom logs table.
	// Validate the table name to ensure it contains only safe characters
	// before constructing the DROP TABLE statement (defense in depth).
	$table_name = $wpdb->prefix . 'crocina_form_logs';
	if ( 1 === preg_match( '/^[a-zA-Z0-9_]+$/', $table_name ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	// 3. Delete all crocina_form posts (and their meta).
	$form_ids = get_posts( array(
		'post_type'      => 'crocina_form',
		'post_status'    => 'any',
		'numberposts'    => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'suppress_filters' => true,
	) );

	foreach ( (array) $form_ids as $form_id ) {
		wp_delete_post( (int) $form_id, true );
	}

	// 4. Delete any lingering plugin post meta (defensive, in case posts remain).
	$meta_keys = array( 'crocina_fields', 'crocina_form_design', 'crocina_alert_options' );
	foreach ( $meta_keys as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}

	// 5. Delete plugin transients (rate limiting + webhook limits).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\_transient\_crocina\_rate\_%'
		    OR option_name LIKE '\_transient\_timeout\_crocina\_rate\_%'
		    OR option_name LIKE '\_transient\_crocina\_webhook\_limit\_%'
		    OR option_name LIKE '\_transient\_timeout\_crocina\_webhook\_limit\_%'"
	);

	// 6. Clear scheduled prune event.
	wp_clear_scheduled_hook( 'crocina_forms_prune_logs' );
}

if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( (array) $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		crocina_forms_uninstall_site();
		restore_current_blog();
	}
} else {
	crocina_forms_uninstall_site();
}
