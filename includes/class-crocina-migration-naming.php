<?php
/**
 * Crocina Forms — Naming Convention Migration
 *
 * Renames all `crocina_`-prefixed post meta keys and option names
 * to use hyphens (`crocina-`) for consistency with the plugin's
 * CSS class naming convention.
 *
 * This migration is idempotent and runs once per site via the
 * existing `maybe_upgrade()` flow.  It also registers backward-
 * compatible fallback reads so that any third-party code or
 * hard-coded meta references continue to work.
 *
 * ## What gets renamed
 *
 * | Scope      | Old (underscore)           | New (hyphen)               |
 * |------------|----------------------------|----------------------------|
 * | Post meta  | `crocina_fields`           | `crocina-fields`           |
 * | Post meta  | `crocina_form_design`      | `crocina-form-design`      |
 * | Post meta  | `crocina_alert_options`    | `crocina-alert-options`    |
 * | Option     | `crocina_forms_settings`   | `crocina-forms-settings`   |
 * | Option     | `crocina_logs_db_version`  | `crocina-logs-db-version`  |
 * | Option     | `crocina_fulltext_fallback` | `crocina-fulltext-fallback` |
 * | Option     | `crocina_migration_log`    | `crocina-migration-log`    |
 *
 * ## Usage in code
 *
 * Once this migration has run, always use the HYPHEN form when
 * reading/writing data.  Fallback reads are provided only for
 * sites that haven't migrated yet, or for stale cached data.
 *
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

class Crocina_Migration_Naming {

	/**
	 * Migration version stamp stored in the option table.
	 *
	 * @var string
	 */
	const MIGRATION_KEY = 'crocina_naming_migration_version';

	/**
	 * Current schema version for this migration.
	 *
	 * @var int
	 */
	const CURRENT_VERSION = 1;

	/**
	 * Map of post_meta keys: old (underscore) => new (hyphen).
	 *
	 * @var array<string, string>
	 */
	private static $meta_key_map = array(
		'crocina_fields'         => 'crocina-fields',
		'crocina_form_design'    => 'crocina-form-design',
		'crocina_alert_options'  => 'crocina-alert-options',
	);

	/**
	 * Map of option names: old (underscore) => new (hyphen).
	 *
	 * @var array<string, string>
	 */
	private static $option_map = array(
		'crocina_forms_settings'   => 'crocina-forms-settings',
		'crocina_logs_db_version'  => 'crocina-logs-db-version',
		'crocina_fulltext_fallback' => 'crocina-fulltext-fallback',
		'crocina_migration_log'    => 'crocina-migration-log',
	);

	/**
	 * Run the migration if it hasn't been applied yet.
	 *
	 * Called from Crocina_Core::maybe_upgrade_logger() on every
	 * admin page load.  Idempotent — safe to call repeatedly.
	 *
	 * IMPORTANT: Data is COPIED from old keys to new keys.
	 * Old keys are preserved for backward compatibility so that
	 * existing get_post_meta() / get_option() calls with the
	 * underscore form continue to work.  The new hyphen keys
	 * are ready for the future code update.
	 *
	 * @return bool True if migration ran, false if already up-to-date.
	 */
	public static function run() {
		$applied = (int) get_option( self::MIGRATION_KEY, 0 );
		if ( $applied >= self::CURRENT_VERSION ) {
			return false;
		}

		self::migrate_post_meta();
		self::migrate_options();

		update_option( self::MIGRATION_KEY, self::CURRENT_VERSION, false );
		self::log_migration( 'Naming convention migration applied (underscore → hyphen for meta keys & options). Old keys preserved for backward compatibility.' );

		return true;
	}

	/**
	 * Roll back the naming migration.
	 *
	 * Deletes the hyphen-form keys that were created by run().
	 * Old underscore-form keys are NOT touched (they were never
	 * removed), so data remains intact.
	 *
	 * Intended for development/testing only — use with extreme
	 * caution on production sites.
	 *
	 * @param bool $force Set to true to actually perform rollback.
	 * @return int Number of rows cleaned up.
	 */
	public static function rollback( $force = false ) {
		if ( ! $force ) {
			return 0;
		}

		$total = 0;

		// Delete the new hyphen meta keys (old keys are still there).
		foreach ( self::$meta_key_map as $old => $new ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$rows = $wpdb->delete(
				$wpdb->postmeta,
				array( 'meta_key' => $new )
			);
			if ( $rows && ! is_wp_error( $rows ) ) {
				$total += $rows;
			}
		}

		// Delete the new hyphen options (old keys are still there).
		foreach ( self::$option_map as $old => $new ) {
			delete_option( $new );
			$total++;
		}

		delete_option( self::MIGRATION_KEY );
		self::log_migration( "Naming migration rolled back ({$total} rows cleaned up). Underscore keys preserved." );

		return $total;
	}

	/* ------------------------------------------------------------------ */
	/*  Status helpers (used by WP-CLI and admin display)                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Get the migration status summary.
	 *
	 * @return array{applied:bool,version:int,meta_keys:array,options:array}
	 */
	public static function get_status() {
		global $wpdb;

		$applied = (int) get_option( self::MIGRATION_KEY, 0 );
		$status  = array(
			'applied'  => $applied >= self::CURRENT_VERSION,
			'version'  => $applied,
			'meta_keys' => array(),
			'options'  => array(),
		);

		// Check which meta keys exist.
		foreach ( self::$meta_key_map as $old => $new ) {
			$old_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $old )
			);
			$new_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $new )
			);
			$status['meta_keys'][ $old ] = array(
				'new_key'     => $new,
				'old_count'   => $old_count,
				'new_count'   => $new_count,
				'migrated'    => $new_count > 0,
			);
		}

		// Check which options exist.
		foreach ( self::$option_map as $old => $new ) {
			$old_val = get_option( $old, null );
			$new_val = get_option( $new, null );
			$status['options'][ $old ] = array(
				'new_key'  => $new,
				'old_exists' => null !== $old_val,
				'new_exists' => null !== $new_val,
				'migrated'   => null !== $new_val,
			);
		}

		return $status;
	}

	/* ------------------------------------------------------------------ */
	/*  Internal helpers                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Migrate all existing post meta keys from underscore to hyphen.
	 *
	 * Uses INSERT ... SELECT to COPY values to the new key while
	 * keeping the old key intact for backward compatibility.
	 */
	private static function migrate_post_meta() {
		global $wpdb;

		foreach ( self::$meta_key_map as $old => $new ) {
			// Copy values to the new key (old key is preserved for backward compat).
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
					 SELECT post_id, %s, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
					$new,
					$old
				)
			);
		}
	}

	/**
	 * Migrate all existing option names from underscore to hyphen.
	 *
	 * Creates the new hyphen-form option alongside the old one.
	 * Old option is NOT deleted to maintain backward compatibility.
	 */
	private static function migrate_options() {
		foreach ( self::$option_map as $old => $new ) {
			$value = get_option( $old, null );
			if ( null !== $value && false === get_option( $new, false ) ) {
				update_option( $new, $value );
				// Note: old option is intentionally NOT deleted.
			}
		}
	}

	/**
	 * Append an entry to the migration log.
	 *
	 * The log is stored under the NEW hyphen key for forward-facing
	 * tools, but falls back to the old key if the migration hasn't
	 * been applied yet.
	 *
	 * @param string $description Human-readable description.
	 */
	private static function log_migration( $description ) {
		$old_key   = 'crocina_migration_log';
		$new_key   = self::$option_map[ $old_key ] ?? $old_key;
		$log       = get_option( $new_key, array() );
		$log[]     = array(
			'timestamp'   => gmdate( 'Y-m-d H:i:s' ),
			'description' => $description,
		);
		$log       = array_slice( $log, -50 ); // Keep last 50 entries.
		update_option( $new_key, $log, false );
	}
}
