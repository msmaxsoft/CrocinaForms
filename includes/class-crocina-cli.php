<?php
/**
 * WP-CLI commands for Crocina Forms.
 *
 * ## USAGE
 *
 *     # Flush all caches.
 *     wp crocina cache flush --all
 *
 *     # Flush global settings cache only.
 *     wp crocina cache flush --settings
 *
 *     # Flush design cache for one form.
 *     wp crocina cache flush --design=123
 *
 *     # Flush design cache for multiple forms.
 *     wp crocina cache flush --design=123,456,789
 *
 *     # Show current cache status.
 *     wp crocina cache status
 *
 *     # Warm all caches after a flush (settings + all form designs).
 *     wp crocina cache warm
 *
 *     # Warm only specific forms.
 *     wp crocina cache warm --form=7,12,99
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage Crocina Forms caches from the command line.
 */
class Crocina_CLI extends WP_CLI_Command {

	/**
	 * Flush Crocina Forms object caches.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Flush all caches (settings + all form designs).
	 *
	 * [--settings]
	 * : Flush only the global settings cache.
	 *
	 * [--design=<ids>]
	 * : Flush design cache for one or more form IDs (comma-separated).
	 *
	 * ## EXAMPLES
	 *
	 *     wp crocina cache flush --all
	 *     wp crocina cache flush --settings
	 *     wp crocina cache flush --design=42
	 *     wp crocina cache flush --design=7,12,99
	 *
	 * @subcommand flush
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative flags.
	 * @return void
	 */
	public function flush( $args, $assoc_args ) {
		$app = Crocina_App::instance();
		/** @var Crocina_Forms_Core $core */
		$core = $app->get( 'core' );

		if ( ! $core ) {
			WP_CLI::error( 'Crocina Forms core service is not available.' );
		}

		$flushed = array();

		// --all: flush everything.
		if ( isset( $assoc_args['all'] ) && $assoc_args['all'] ) {
			$core->flush_settings_cache();

			// Flush all published form designs.
			$forms = get_posts( array(
				'post_type'      => 'crocina_form',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			) );
			foreach ( $forms as $form_id ) {
				$core->flush_form_design_cache( $form_id );
			}

			// Also flush the logger's fallback-mode cache if it exists.
			if ( $app->has( 'logger' ) ) {
				/** @var Crocina_Logger $logger */
				$logger = $app->get( 'logger' );
				if ( method_exists( $logger, 'flush_fallback_cache' ) ) {
					$logger->flush_fallback_cache();
				}
			}

			$flushed[] = sprintf( 'all caches (%d form designs + settings + logger)', count( $forms ) );
		}

		// --settings: flush global settings cache.
		if ( isset( $assoc_args['settings'] ) && $assoc_args['settings'] ) {
			$core->flush_settings_cache();
			$flushed[] = 'settings cache';
		}

		// --design=<ids>: flush specific form design caches.
		if ( isset( $assoc_args['design'] ) && '' !== $assoc_args['design'] ) {
			$ids = array_filter( array_map( 'absint', explode( ',', $assoc_args['design'] ) ) );
			if ( empty( $ids ) ) {
				WP_CLI::warning( 'No valid form IDs provided for --design.' );
			} else {
				foreach ( $ids as $id ) {
					$core->flush_form_design_cache( $id );
				}
				$flushed[] = sprintf( 'design cache for form(s): %s', implode( ', ', $ids ) );
			}
		}

		if ( empty( $flushed ) ) {
			WP_CLI::warning( 'Nothing to flush. Use --all, --settings, or --design=<ids>.' );
			return;
		}

		WP_CLI::success( 'Flushed: ' . implode( '; ', $flushed ) );
	}

	/**
	 * Warm (pre-build) object-cache entries for all active forms.
	 *
	 * Use this after `wp crocina cache flush --all` (or a Redis flush)
	 * to re-prime all caches so the first frontend visitor to each form
	 * doesn't experience a cold-cache penalty.
	 *
	 * ## OPTIONS
	 *
	 * [--form=<ids>]
	 * : Comma-separated list of form IDs to warm. Omit to warm all published forms.
	 *
	 * [--quiet]
	 * : Suppress per-form progress output.
	 *
	 * ## EXAMPLES
	 *
	 *     # Warm all published forms after a full flush.
	 *     wp crocina cache warm
	 *
	 *     # Warm specific forms only.
	 *     wp crocina cache warm --form=7,12,99
	 *
	 *     # Warm silently (no per-form output).
	 *     wp crocina cache warm --quiet
	 *
	 * @subcommand warm
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative flags.
	 * @return void
	 */
	public function warm( $args, $assoc_args ) {
		$app = Crocina_App::instance();
		/** @var Crocina_Forms_Core $core */
		$core = $app->get( 'core' );

		if ( ! $core ) {
			WP_CLI::error( 'Crocina Forms core service is not available.' );
		}

		$quiet = isset( $assoc_args['quiet'] ) && $assoc_args['quiet'];

		// Resolve which forms to warm.
		if ( isset( $assoc_args['form'] ) && '' !== $assoc_args['form'] ) {
			$ids = array_filter( array_map( 'absint', explode( ',', $assoc_args['form'] ) ) );
			if ( empty( $ids ) ) {
				WP_CLI::error( 'No valid form IDs provided via --form.' );
			}
			$forms = get_posts( array(
				'post_type'      => 'crocina_form',
				'post_status'    => 'publish',
				'post__in'       => $ids,
				'posts_per_page' => count( $ids ),
			) );
		} else {
			$forms = get_posts( array(
				'post_type'      => 'crocina_form',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			) );
		}

		$total = count( $forms );
		if ( empty( $forms ) ) {
			WP_CLI::warning( 'No published forms found to warm.' );
			return;
		}

		$success = 0;
		$errors  = array();

		// Warm global settings once (shared across all forms).
		$core->get_global_settings();

		if ( ! $quiet ) {
			WP_CLI::line( "Warming cache for {$total} form(s)…" );
			$progress = \WP_CLI\Utils\make_progress_bar( 'Warming forms', $total );
		}

		foreach ( $forms as $form_post ) {
			$form_id = $form_post->ID;
			try {
				$core->warm_cache( $form_id, $form_post );
				++$success;

				if ( ! $quiet && isset( $progress ) ) {
					$progress->tick();
				}
			} catch ( \Exception $e ) {
				$errors[] = $form_id;
				if ( ! $quiet ) {
					WP_CLI::warning( "Form #{$form_id}: " . $e->getMessage() );
				}
			}
		}

		if ( ! $quiet && isset( $progress ) ) {
			$progress->finish();
		}

		WP_CLI::line( '' );
		$persistent = wp_using_ext_object_cache();
		$mode = $persistent ? 'object cache' : 'transients';
		WP_CLI::success( "Warmed {$success} / {$total} form(s) — settings + designs stored in {$mode}." );

		if ( ! empty( $errors ) ) {
			WP_CLI::warning( 'Errors occurred for form(s): ' . implode( ', ', $errors ) );
		}
	}

	/**
	 * Display the current status of Crocina Forms caches.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crocina cache status
	 *
	 * @subcommand status
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative flags (unused).
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		$app  = Crocina_App::instance();
		$core = $app->get( 'core' );

		if ( ! $core ) {
			WP_CLI::error( 'Crocina Forms core service is not available.' );
		}

		// Check settings cache.
		$settings_cached = wp_cache_get( 'crocina_global_settings', 'crocina_forms' );
		$settings_status = false !== $settings_cached ? 'YES' : 'NO';

		// Check design caches for a few recent forms.
		$forms = get_posts( array(
			'post_type'      => 'crocina_form',
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'fields'         => 'ids',
		) );

		$cached_count = 0;
		foreach ( $forms as $form_id ) {
			$design = wp_cache_get( 'crocina_form_design_' . $form_id, 'crocina_forms' );
			if ( false !== $design ) {
				++$cached_count;
			}
		}

		WP_CLI::line( '--- Crocina Forms Cache Status ---' );
		WP_CLI::line( "Settings cached:         {$settings_status}" );
		WP_CLI::line( "Total published forms:   " . count( $forms ) );
		WP_CLI::line( "Designs cached (sample): {$cached_count} / " . count( $forms ) );
		WP_CLI::line( '' );

		// Check if Logger has a fallback mode.
		if ( $app->has( 'logger' ) ) {
			/** @var Crocina_Logger $logger */
			$logger = $app->get( 'logger' );
			if ( method_exists( $logger, 'get_fallback_mode' ) ) {
				$mode = $logger->get_fallback_mode();
				WP_CLI::line( "FULLTEXT fallback mode:  " . ( $mode ?: 'none (native)' ) );
			}
		}
	}
}

/**
 * Pre-build (warm) object-cache entries for all active forms.
 *
 * Use this after deploying code, flushing Redis, clearing the object
 * cache, or enabling a new cache backend so the first visitor to each
 * form doesn't experience a cold-cache penalty.
 *
 * ## OPTIONS
 *
 * [--form=<ids>]
 * : Comma-separated list of form IDs to warm. Omitting warms all published forms.
 *
 * [--quiet]
 * : Suppress per-form progress output.
 *
 * ## EXAMPLES
 *
 *     # Warm cache for all published forms.
 *     wp crocina warm-cache
 *
 *     # Warm only specific forms.
 *     wp crocina warm-cache --form=7,12,99
 *
 *     # Warm silently (no per-form output).
 *     wp crocina warm-cache --quiet
 *
 * @when after_wp_load
 */
class Crocina_WarmCache_CLI extends WP_CLI_Command {

	/**
	 * Pre-build object-cache entries for forms.
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative flags.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$app = Crocina_App::instance();
		/** @var Crocina_Forms_Core $core */
		$core = $app->get( 'core' );

		if ( ! $core ) {
			WP_CLI::error( 'Crocina Forms core service is not available.' );
		}

		$quiet = isset( $assoc_args['quiet'] ) && $assoc_args['quiet'];

		// Resolve which forms to warm — get full post objects to avoid
		// extra get_post() calls inside the loop.
		if ( isset( $assoc_args['form'] ) && '' !== $assoc_args['form'] ) {
			$ids = array_filter( array_map( 'absint', explode( ',', $assoc_args['form'] ) ) );
			if ( empty( $ids ) ) {
				WP_CLI::error( 'No valid form IDs provided via --form.' );
			}
			$forms = get_posts( array(
				'post_type'      => 'crocina_form',
				'post_status'    => 'publish',
				'post__in'       => $ids,
				'posts_per_page' => count( $ids ),
			) );
		} else {
			$forms = get_posts( array(
				'post_type'      => 'crocina_form',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			) );
		}

		$total = count( $forms );
		if ( empty( $forms ) ) {
			WP_CLI::warning( 'No published forms found to warm.' );
			return;
		}

		$success = 0;
		$errors  = array();

		// Warm global settings once (shared across all forms).
		$core->get_global_settings();

		if ( ! $quiet ) {
			WP_CLI::line( "Warming cache for {$total} form(s)…" );
			WP_CLI::line( '' );
			$progress = \WP_CLI\Utils\make_progress_bar( 'Warming forms', $total );
		}

		foreach ( $forms as $form_post ) {
			$form_id = $form_post->ID;
			try {
				$core->warm_cache( $form_id, $form_post );
				++$success;

				if ( ! $quiet ) {
					if ( isset( $progress ) ) {
						$progress->tick();
					} else {
						WP_CLI::line( "  [OK] Form #{$form_id}" );
					}
				}
			} catch ( \Exception $e ) {
				$errors[] = $form_id;
				if ( ! $quiet ) {
					WP_CLI::warning( "Form #{$form_id}: " . $e->getMessage() );
				}
			}
		}

		if ( ! $quiet && isset( $progress ) ) {
			$progress->finish();
		}

		WP_CLI::line( '' );
		WP_CLI::success( "Warmed {$success} / {$total} form(s) — settings cache is also primed." );

		if ( ! empty( $errors ) ) {
			WP_CLI::warning( 'Errors occurred for form(s): ' . implode( ', ', $errors ) );
		}
	}
}

/**
 * Manage Crocina Forms event log from the command line.
 */
class Crocina_Events_CLI extends WP_CLI_Command {

	/**
	 * Get the type_map for --type filter.
	 *
	 * Maps short filter keywords to actual event name substrings
	 * as they appear in the log file.
	 *
	 * @return array<string, string>
	 */
	private function get_type_map() {
		return array(
			'submit' => 'crocina_form_submitted',
			'notify' => 'notification.sent',
			'log'    => 'log.created',
			'error'  => 'error',
		);
	}

	/**
	 * Export event log entries to CSV or JSON, with optional date and type filters.
	 *
	 * Parses the events.log file, applies filters, and outputs structured data
	 * for analysis, reporting, or import into external tools.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: csv, json. Default: csv.
	 *
	 * [--from=<date>]
	 * : Include entries on or after this date (inclusive). Format: YYYY-MM-DD.
	 *
	 * [--to=<date>]
	 * : Include entries on or before this date (inclusive). Format: YYYY-MM-DD.
	 *
	 * [--type=<type>]
	 * : Filter by event type. Accepts: submit, notify, log, error, or a partial
	 *   event name string. Default: all.
	 *
	 * [--output=<file>]
	 * : Write output to a file instead of stdout. Path relative to the current
	 *   working directory, or absolute.
	 *
	 * [--flatten]
	 * : (CSV only) Flatten JSON payload keys into separate CSV columns.
	 *   When omitted (default), the payload is included as a single JSON column.
	 *
	 * ## EXAMPLES
	 *
	 *     # Export all events as CSV to stdout.
	 *     wp crocina events export
	 *
	 *     # Export as JSON with date range.
	 *     wp crocina events export --format=json --from=2024-01-01 --to=2024-12-31
	 *
	 *     # Export only form submission events to a file.
	 *     wp crocina events export --type=submit --output=./submissions.csv
	 *
	 *     # Export with flattened payload columns for spreadsheet import.
	 *     wp crocina events export --format=csv --flatten
	 *
	 * @subcommand export
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative flags.
	 * @return void
	 */
	public function export( $args, $assoc_args ) {
		$log_file = $this->get_log_file_path();

		if ( ! $log_file || ! is_file( $log_file ) ) {
			WP_CLI::warning( 'Event log file not found. Make sure event logging is enabled in Settings > General.' );
			return;
		}

		$format  = isset( $assoc_args['format'] ) ? strtolower( $assoc_args['format'] ) : 'csv';
		$from    = isset( $assoc_args['from'] ) ? trim( $assoc_args['from'] ) : '';
		$to      = isset( $assoc_args['to'] ) ? trim( $assoc_args['to'] ) : '';
		$type    = isset( $assoc_args['type'] ) ? strtolower( $assoc_args['type'] ) : 'all';
		$output  = isset( $assoc_args['output'] ) ? trim( $assoc_args['output'] ) : '';
		$flatten = isset( $assoc_args['flatten'] ) && $assoc_args['flatten'];

		if ( ! in_array( $format, array( 'csv', 'json' ), true ) ) {
			WP_CLI::error( 'Invalid format. Use: csv or json.' );
		}

		// Validate dates if provided.
		$from_ts = 0;
		$to_ts   = 0;
		if ( '' !== $from ) {
			$from_ts = strtotime( $from );
			if ( false === $from_ts ) {
				WP_CLI::error( 'Invalid --from date. Use YYYY-MM-DD format.' );
			}
		}
		if ( '' !== $to ) {
			$to_ts = strtotime( $to );
			if ( false === $to_ts ) {
				WP_CLI::error( 'Invalid --to date. Use YYYY-MM-DD format.' );
			}
			// End of the day (23:59:59) so the whole day is included.
			$to_ts = $to_ts + 86399;
		}

		// Map type filter to a substring.
		$type_needle = '';
		if ( 'all' !== $type ) {
			$type_map = $this->get_type_map();
			$type_needle = isset( $type_map[ $type ] ) ? $type_map[ $type ] : $type;
		}

		// Read and parse the log file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $log_file );
		if ( false === $content || '' === $content ) {
			WP_CLI::warning( 'Event log is empty.' );
			return;
		}

		$raw_lines  = explode( "\n", $content );
		$parsed     = array();
		$total      = 0;
		$filtered   = 0;

		foreach ( $raw_lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			++$total;

			// Parse line format: [YYYY-MM-DD HH:MM:SS] event_name  {...json...}
			if ( ! preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+(\S+)\s+(\{.*\})\s*$/', $line, $m ) ) {
				// Lines that don't match the standard format are skipped silently.
				continue;
			}

			$timestamp  = $m[1];
			$event_name = $m[2];
			$json_raw   = $m[3];

			$parsed_ts = strtotime( $timestamp );
			if ( false === $parsed_ts ) {
				continue;
			}

			// Apply date filters.
			if ( $from_ts > 0 && $parsed_ts < $from_ts ) {
				continue;
			}
			if ( $to_ts > 0 && $parsed_ts > $to_ts ) {
				// Log is chronological (oldest first), so stop early if we've passed the end date.
				break;
			}

			// Apply type filter.
			if ( '' !== $type_needle && false === strpos( $event_name, $type_needle ) ) {
				continue;
			}

			// Decode JSON payload.
			$payload = json_decode( $json_raw, true );
			if ( null === $payload ) {
				$payload = array( '_raw' => $json_raw );
			}

			$parsed[] = array(
				'timestamp'  => $timestamp,
				'event_name' => $event_name,
				'payload'    => $payload,
			);

			++$filtered;
		}

		if ( empty( $parsed ) ) {
			WP_CLI::warning( 'No matching event log entries found.' );
			return;
		}

		// Build output.
		if ( 'json' === $format ) {
			$output_content = $this->format_json_export( $parsed );
		} else {
			$output_content = $this->format_csv_export( $parsed, $flatten );
		}

		// Write to file or stdout.
		if ( '' !== $output ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
			$written = file_put_contents( $output, $output_content );
			if ( false === $written ) {
				WP_CLI::error( "Unable to write to file: {$output}" );
			}

			$size_human = $written > 1024 * 1024
				? round( $written / ( 1024 * 1024 ), 1 ) . ' MB'
				: round( $written / 1024, 1 ) . ' KB';

			WP_CLI::success( sprintf(
				'Exported %d of %d entries to %s (%s).',
				$filtered,
				$total,
				$output,
				$size_human
			) );
		} else {
			// Output to stdout.
			WP_CLI::line( $output_content );
			WP_CLI::debug( sprintf( 'Exported %d of %d entries.', $filtered, $total ) );
		}
	}

	/**
	 * Format parsed entries as JSON array.
	 *
	 * @param array $entries List of parsed log entries.
	 * @return string
	 */
	private function format_json_export( array $entries ) {
		// Build a clean array of objects with timestamp, event_name, and payload keys.
		$output = array();
		foreach ( $entries as $entry ) {
			$row = array(
				'timestamp'  => $entry['timestamp'],
				'event_name' => $entry['event_name'],
			);
			// Merge payload keys at the top level for cleaner output.
			if ( is_array( $entry['payload'] ) ) {
				foreach ( $entry['payload'] as $k => $v ) {
					$row[ $k ] = $v;
				}
			} else {
				$row['payload'] = $entry['payload'];
			}
			$output[] = $row;
		}

		return wp_json_encode( $output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n";
	}

	/**
	 * Format parsed entries as CSV.
	 *
	 * @param array $entries List of parsed log entries.
	 * @param bool  $flatten Whether to flatten payload keys into separate columns.
	 * @return string
	 */
	private function format_csv_export( array $entries, $flatten = false ) {
		if ( empty( $entries ) ) {
			return '';
		}

		$output = '';

		// Discover all payload keys when flattening.
		$all_payload_keys = array();
		if ( $flatten ) {
			foreach ( $entries as $entry ) {
				if ( is_array( $entry['payload'] ) ) {
					foreach ( $entry['payload'] as $k => $v ) {
						if ( ! in_array( $k, $all_payload_keys, true ) ) {
							$all_payload_keys[] = $k;
						}
					}
				}
			}
			sort( $all_payload_keys );
		}

		// Header row.
		$header = array( 'timestamp', 'event_name' );
		if ( $flatten ) {
			foreach ( $all_payload_keys as $pk ) {
				$header[] = $pk;
			}
		} else {
			$header[] = 'payload';
		}
		$output .= $this->csv_escape_row( $header ) . "\n";

		// Data rows.
		foreach ( $entries as $entry ) {
			$row = array( $entry['timestamp'], $entry['event_name'] );

			if ( $flatten ) {
				foreach ( $all_payload_keys as $pk ) {
					$val = isset( $entry['payload'][ $pk ] ) ? $entry['payload'][ $pk ] : '';
					if ( is_array( $val ) ) {
						$val = wp_json_encode( $val, JSON_UNESCAPED_UNICODE );
					}
					$row[] = $val;
				}
			} else {
				// Single JSON column for the entire payload.
				$row[] = wp_json_encode( $entry['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}

			$output .= $this->csv_escape_row( $row ) . "\n";
		}

		return $output;
	}

	/**
	 * Properly escape a row of values for CSV output.
	 *
	 * Wraps values containing commas, double-quotes, or newlines in
	 * double-quotes and escapes embedded double-quotes.
	 *
	 * @param array $fields Row fields.
	 * @return string
	 */
	private function csv_escape_row( array $fields ) {
		$escaped = array();
		foreach ( $fields as $val ) {
			$val = (string) $val;
			// Escape double-quotes.
			if ( false !== strpos( $val, '"' ) ) {
				$val = str_replace( '"', '""', $val );
			}
			// Wrap in quotes if it contains comma, double-quote, or newline.
			if ( false !== strpos( $val, ',' ) || false !== strpos( $val, '"' ) || false !== strpos( $val, "\n" ) || false !== strpos( $val, "\r" ) ) {
				$val = '"' . $val . '"';
			}
			$escaped[] = $val;
		}
		return implode( ',', $escaped );
	}

	/**
	 * Display statistics about the event log — total entries, breakdown by
	 * event type, most frequent hours, and more.
	 *
	 * Parses the entire events.log file and computes aggregate metrics.
	 * Useful for a quick pulse check without leaving the terminal.
	 *
	 * ## OPTIONS
	 *
	 * [--top=<num>]
	 * : Number of top hours to show. Default 5.
	 *
	 * [--from=<date>]
	 * : Include entries on or after this date (inclusive). Format: YYYY-MM-DD.
	 *
	 * [--to=<date>]
	 * : Include entries on or before this date (inclusive). Format: YYYY-MM-DD.
	 *
	 * [--raw]
	 * : Output raw JSON instead of formatted table (useful for scripting).
	 *
	 * ## EXAMPLES
	 *
	 *     # Show default stats.
	 *     wp crocina events stats
	 *
	 *     # Show top 10 busiest hours.
	 *     wp crocina events stats --top=10
	 *
	 *     # Stats for a date range.
	 *     wp crocina events stats --from=2024-06-01 --to=2024-06-30
	 *
	 *     # Raw JSON output.
	 *     wp crocina events stats --raw | jq '.by_type'
	 *
	 * @subcommand stats
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative flags.
	 * @return void
	 */
	public function stats( $args, $assoc_args ) {
		$log_file = $this->get_log_file_path();

		if ( ! $log_file || ! is_file( $log_file ) ) {
			WP_CLI::warning( 'Event log file not found. Make sure event logging is enabled in Settings > General.' );
			return;
		}

		$top  = absint( $assoc_args['top'] ?? 5 );
		$top  = max( 1, min( $top, 48 ) );
		$from = isset( $assoc_args['from'] ) ? trim( $assoc_args['from'] ) : '';
		$to   = isset( $assoc_args['to'] ) ? trim( $assoc_args['to'] ) : '';
		$raw  = isset( $assoc_args['raw'] ) && $assoc_args['raw'];

		// Validate dates.
		$from_ts = 0;
		$to_ts   = 0;
		if ( '' !== $from ) {
			$from_ts = strtotime( $from );
			if ( false === $from_ts ) {
				WP_CLI::error( 'Invalid --from date. Use YYYY-MM-DD format.' );
			}
		}
		if ( '' !== $to ) {
			$to_ts = strtotime( $to );
			if ( false === $to_ts ) {
				WP_CLI::error( 'Invalid --to date. Use YYYY-MM-DD format.' );
			}
			$to_ts += 86399;
		}

		// Read and parse.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $log_file );
		if ( false === $content || '' === $content ) {
			WP_CLI::warning( 'Event log is empty.' );
			return;
		}

		$raw_lines = explode( "\n", $content );

		$total      = 0;
		$parsed     = 0;
		$by_type    = array();
		$by_hour    = array();  // 'YYYY-MM-DD HH:00' => count
		$by_day     = array();  // 'YYYY-MM-DD' => count
		$form_ids   = array();  // set of unique form IDs from submit events
		$first_date = '';
		$last_date  = '';

		foreach ( $raw_lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			++$total;

			if ( ! preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+(\S+)\s+(\{.*\})\s*$/', $line, $m ) ) {
				continue;
			}

			$ts_str     = $m[1];
			$event_name = $m[2];
			$json_raw   = $m[3];

			$parsed_ts = strtotime( $ts_str );
			if ( false === $parsed_ts ) {
				continue;
			}

			// Date range filter.
			if ( $from_ts > 0 && $parsed_ts < $from_ts ) {
				continue;
			}
			if ( $to_ts > 0 && $parsed_ts > $to_ts ) {
				break;
			}

			++$parsed;

			// Track first/last date.
			if ( '' === $first_date || $ts_str < $first_date ) {
				$first_date = $ts_str;
			}
			if ( '' === $last_date || $ts_str > $last_date ) {
				$last_date = $ts_str;
			}

			// Classify event type.
			$type_key = 'other';
			if ( false !== strpos( $event_name, 'crocina_form_submitted' ) || false !== strpos( $event_name, 'form_after_submission' ) ) {
				$type_key = 'submit';
			} elseif ( false !== strpos( $event_name, 'notification.sent' ) ) {
				$type_key = 'notify';
			} elseif ( false !== strpos( $event_name, 'after_log_insert' ) || false !== strpos( $event_name, 'log.created' ) ) {
				$type_key = 'log';
			} elseif ( false !== strpos( $event_name, 'error' ) ) {
				$type_key = 'error';
			}

			if ( ! isset( $by_type[ $type_key ] ) ) {
				$by_type[ $type_key ] = 0;
			}
			++$by_type[ $type_key ];

			// Hour bucket (YYYY-MM-DD HH:00).
			$hour_key = substr( $ts_str, 0, 14 ) . '00';
			if ( ! isset( $by_hour[ $hour_key ] ) ) {
				$by_hour[ $hour_key ] = 0;
			}
			++$by_hour[ $hour_key ];

			// Day bucket.
			$day_key = substr( $ts_str, 0, 10 );
			if ( ! isset( $by_day[ $day_key ] ) ) {
				$by_day[ $day_key ] = 0;
			}
			++$by_day[ $day_key ];

			// Unique form IDs from submit events (extract from JSON payload).
			if ( 'submit' === $type_key ) {
				$payload = json_decode( $json_raw, true );
				if ( is_array( $payload ) && isset( $payload['form_id'] ) ) {
					$form_ids[ (int) $payload['form_id'] ] = true;
				}
			}
		}

		if ( 0 === $parsed ) {
			WP_CLI::warning( 'No matching log entries found in the specified range.' );
			return;
		}

		// Sort hours descending by count, take top N.
		arsort( $by_hour );
		$top_hours = array_slice( $by_hour, 0, $top, true );

		// Most active day.
		arsort( $by_day );
		$top_day = key( $by_day );
		$top_day_count = $by_day[ $top_day ];

		// Build stats array.
		$stats = array(
			'total_lines'     => $total,
			'parsed_entries'  => $parsed,
			'date_range'      => array(
				'first' => $first_date,
				'last'  => $last_date,
			),
			'by_type'         => $by_type,
			'by_type_pct'     => array(),
			'unique_forms'    => count( $form_ids ),
			'avg_per_day'     => 0,
			'top_day'         => $top_day,
			'top_day_count'   => $top_day_count,
			'top_hours'       => $top_hours,
		);

		// Compute percentages.
		foreach ( $by_type as $k => $v ) {
			$stats['by_type_pct'][ $k ] = round( ( $v / max( 1, $parsed ) ) * 100, 1 );
		}

		// Average per day.
		$day_count = count( $by_day );
		$stats['avg_per_day'] = $day_count > 0 ? round( $parsed / $day_count, 1 ) : $parsed;
		$stats['total_days']  = $day_count;

		// Raw JSON output.
		if ( $raw ) {
			WP_CLI::line( wp_json_encode( $stats, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			return;
		}

		// Formatted table output.
		$this->output_stats( $stats, $top );
	}

	/**
	 * Render the stats summary as a formatted CLI table.
	 *
	 * @param array $stats Computed statistics.
	 * @param int   $top   Number of top hours shown.
	 * @return void
	 */
	private function output_stats( array $stats, $top ) {
		$s = $stats;

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%c╔══════════════════════════════════════════════════╗%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%c║   Crocina Events — Statistics                  ║%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%c╚══════════════════════════════════════════════════╝%n' ) );
		WP_CLI::line( '' );

		// Overview section.
		WP_CLI::line( WP_CLI::colorize( '%B── Overview ──────────────────────────────────────%n' ) );
		WP_CLI::line( sprintf( '  Total log lines :  %d', $s['total_lines'] ) );
		WP_CLI::line( sprintf( '  Parsed entries  :  %s', WP_CLI::colorize( "%G{$s['parsed_entries']}%n" ) ) );
		WP_CLI::line( sprintf( '  Date range      :  %s  →  %s', $s['date_range']['first'], $s['date_range']['last'] ) );
		WP_CLI::line( sprintf( '  Total days      :  %d', $s['total_days'] ) );
		WP_CLI::line( sprintf( '  Avg per day     :  %s', WP_CLI::colorize( "%G{$s['avg_per_day']}%n" ) ) );
		WP_CLI::line( sprintf( '  Unique forms    :  %d', $s['unique_forms'] ) );
		WP_CLI::line( '' );

		// By type section.
		WP_CLI::line( WP_CLI::colorize( '%B── By Event Type ──────────────────────────────────%n' ) );
		$type_labels = array(
			'submit' => 'Submit',
			'notify' => 'Notify',
			'log'    => 'Log',
			'error'  => WP_CLI::colorize( '%RError%n' ),
			'other'  => 'Other',
		);

		// Ordered display: submit, notify, log, error, other.
		$display_order = array( 'submit', 'notify', 'log', 'error', 'other' );
		foreach ( $display_order as $key ) {
			if ( ! isset( $s['by_type'][ $key ] ) ) {
				continue;
			}
			$label = $type_labels[ $key ] ?? ucfirst( $key );
			$count = $s['by_type'][ $key ];
			$pct   = $s['by_type_pct'][ $key ] ?? 0;

			$bar = str_repeat( '█', max( 1, round( $pct / 5 ) ) );
			if ( 'error' === $key && $count > 0 ) {
				$count_str = WP_CLI::colorize( "%R{$count}%n" );
			} else {
				$count_str = $count;
			}

			WP_CLI::line( sprintf(
				'  %-8s  %6s  %5.1f%%  %s',
				$label,
				$count_str,
				$pct,
				$bar
			) );
		}
		WP_CLI::line( '' );

		// Peak hours section.
		WP_CLI::line( WP_CLI::colorize( '%B── Peak Hours (top %d) ──────────────────────────%n', $top ) );
		$rank = 0;
		foreach ( $s['top_hours'] as $hour => $count ) {
			++$rank;
			$bar_len = max( 1, round( ( $count / max( 1, reset( $s['top_hours'] ) ) ) * 30 ) );
			$bar     = str_repeat( '▓', $bar_len );
			$pct_of_max = round( ( $count / max( 1, reset( $s['top_hours'] ) ) ) * 100, 0 );

			WP_CLI::line( sprintf(
				'  #%d  %s  %4d  %s  %3d%%',
				$rank,
				$hour,
				$count,
				$bar,
				$pct_of_max
			) );
		}

		WP_CLI::line( '' );

		// Most active day summary.
		if ( $s['top_day'] ) {
			WP_CLI::line( sprintf(
				'  %s  Busiest day: %s (%d entries)',
				WP_CLI::colorize( '%Y✦%n' ),
				$s['top_day'],
				$s['top_day_count']
			) );
		}

		WP_CLI::line( '' );
		WP_CLI::success( 'Stats computed.' );
	}

	/**
	 * Resolve the path to the events.log file.
	 *
	 * @return string|null Full path or null if directory cannot be created.
	 */
	private function get_log_file_path() {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return null;
		}

		$log_dir = $uploads['basedir'] . '/crocina-forms';
		if ( ! is_dir( $log_dir ) ) {
			if ( ! wp_mkdir_p( $log_dir ) ) {
				return null;
			}
		}

		return $log_dir . '/events.log';
	}

	/**
	 * Send a test event to the configured event webhook URL.
	 *
	 * Builds a sample event payload (matching what Crocina_Event_Webhook
	 * sends) and POSTs it to the endpoint with blocking mode so the
	 * HTTP status code and response body are captured.
	 *
	 * Useful for validating the webhook configuration before real events
	 * start flowing, or for debugging integration issues with external
	 * services like Zapier, n8n, or Slack.
	 *
	 * ## OPTIONS
	 *
	 * [--url=<url>]
	 * : Override the configured event webhook URL (e.g. to test a
	 *   different endpoint without changing Settings).
	 *
	 * [--event=<event>]
	 * : Event name to simulate. Default: crocina_form_submitted.
	 *
	 * [--timeout=<sec>]
	 * : Request timeout in seconds. Default: 10.
	 *
	 * [--verbose]
	 * : Show the full request payload and response body.
	 *
	 * ## EXAMPLES
	 *
	 *     # Test the configured event webhook.
	 *     wp crocina events webhook-test
	 *
	 *     # Test with a custom URL (ignores settings).
	 *     wp crocina events webhook-test --url=https://hooks.zapier.com/...
	 *
	 *     # Simulate a different event type.
	 *     wp crocina events webhook-test --event=crocina_after_log_insert
	 *
	 *     # Show full request/response details.
	 *     wp crocina events webhook-test --verbose
	 *
	 * @subcommand webhook-test
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative flags.
	 * @return void
	 */
	public function webhook_test( $args, $assoc_args ) {
		// Resolve URL: override > settings.
		$url = isset( $assoc_args['url'] ) ? trim( $assoc_args['url'] ) : '';
		if ( '' === $url ) {
			// Read from global settings.
			if ( ! class_exists( 'Crocina_App' ) ) {
				WP_CLI::error( 'Crocina Forms is not loaded.' );
			}
			$app = Crocina_App::instance();
			if ( ! $app->has( 'core' ) ) {
				WP_CLI::error( 'Crocina Forms core service is not available.' );
			}
			/** @var Crocina_Forms_Core $core */
			$core = $app->get( 'core' );
			$settings = $core->get_global_settings();
			$url = isset( $settings['event_webhook_url'] ) ? trim( $settings['event_webhook_url'] ) : '';
			$bearer_token = isset( $settings['event_webhook_bearer_token'] ) ? trim( $settings['event_webhook_bearer_token'] ) : '';
		} else {
			$bearer_token = '';
		}

		if ( '' === $url ) {
			WP_CLI::error(
				'Event webhook URL is not configured. ' .
				'Set it in Settings > Event Webhook, or pass --url=<url>.'
			);
		}

		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			WP_CLI::error( 'Invalid webhook URL: ' . $url );
		}

		$event_name = isset( $assoc_args['event'] ) ? trim( $assoc_args['event'] ) : 'crocina_form_submitted';
		$timeout    = absint( $assoc_args['timeout'] ?? 10 );
		$timeout    = max( 1, min( $timeout, 60 ) );
		$verbose    = isset( $assoc_args['verbose'] ) && $assoc_args['verbose'];

		// Build sample payload matching Crocina_Event_Webhook::on_event() format.
		$payload = array(
			'event'     => $event_name,
			'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'site'      => get_home_url(),
			'data'      => array(
				'form_id'       => 0,
				'fields'        => array(
					array( 'slug' => 'name',    'label' => 'Full Name', 'value' => 'Test User' ),
					array( 'slug' => 'email',   'label' => 'Email',     'value' => 'test@example.com' ),
					array( 'slug' => 'message', 'label' => 'Message',   'value' => 'This is a test event from the Crocina Forms webhook tester.' ),
				),
				'page_title'    => get_bloginfo( 'name' ) . ' (test)',
				'page_url'      => home_url(),
				'submitted_at'  => gmdate( 'Y-m-d H:i:s' ),
				'user_ip'       => '127.0.0.1',
				'is_test'       => true,
			),
		);

		$payload = apply_filters( 'crocina_event_webhook_payload', $payload, $url );
		$json    = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

		if ( false === $json ) {
			WP_CLI::error( 'Failed to encode payload as JSON.' );
		}

		// Display header.
		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%c╔══════════════════════════════════════════════════╗%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%c║   Crocina Event Webhook — Test                  ║%n' ) );
		WP_CLI::line( WP_CLI::colorize( '%c╚══════════════════════════════════════════════════╝%n' ) );
		WP_CLI::line( '' );
		WP_CLI::line( "  Event : {$event_name}" );
		WP_CLI::line( "  URL   : {$url}" );
		WP_CLI::line( '' );

		// Send.
		WP_CLI::line( '  Sending test event…' );

		// Build headers — include Bearer token if configured.
		$test_headers = array(
			'Content-Type'    => 'application/json',
			'X-Crocina-Event' => 'webhook-test',
			'User-Agent'      => 'Crocina Event Webhook Tester/1.0',
		);
		if ( '' !== $bearer_token ) {
			$test_headers['Authorization'] = 'Bearer ' . $bearer_token;
		}

		$http_args = array(
			'method'    => 'POST',
			'timeout'   => $timeout,
			'headers'   => $test_headers,
			'body'      => $json,
			'blocking'  => true,
		);

		$http_args = apply_filters( 'crocina_event_webhook_args', $http_args, $url );

		$start    = microtime( true );
		$response = wp_remote_post( $url, $http_args );
		$elapsed  = round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			WP_CLI::error( 'HTTP request failed: ' . $response->get_error_message() );
			return;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );
		$headers   = wp_remote_retrieve_headers( $response );

		WP_CLI::line( '' );

		// Show result.
		$is_success = $http_code >= 200 && $http_code < 300;
		$icon       = $is_success ? '%G✓%n' : '%R✗%n';
		WP_CLI::line( WP_CLI::colorize( "  {$icon} HTTP {$http_code} — {$elapsed}ms" ) );

		if ( $is_success ) {
			WP_CLI::success( 'Event webhook delivered successfully.' );
		} else {
			WP_CLI::warning( "Event webhook returned HTTP {$http_code}. Check your endpoint." );
		}

		// Verbose: show headers + body.
		if ( $verbose ) {
			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( '%B── Sent Payload ──────────────────────────────────%n' ) );
			$payload_size = strlen( $json );
			$payload_human = $payload_size > 1024
				? round( $payload_size / 1024, 1 ) . ' KB'
				: $payload_size . ' B';
			WP_CLI::line( "  Size: {$payload_human}" );
			WP_CLI::line( $json );

			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( '%B── Response Headers ─────────────────────────────%n' ) );
			if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
				foreach ( $headers->getAll() as $key => $value ) {
					$val_str = is_array( $value ) ? implode( ', ', $value ) : $value;
					WP_CLI::line( "  {$key}: {$val_str}" );
				}
			} else {
				WP_CLI::line( '  (no headers retrieved)' );
			}

			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( '%B── Response Body ────────────────────────────────%n' ) );
			if ( '' !== $body ) {
				$decoded = json_decode( $body, true );
				if ( is_array( $decoded ) ) {
					WP_CLI::line( wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
				} else {
					WP_CLI::line( substr( $body, 0, 2000 ) );
					if ( strlen( $body ) > 2000 ) {
						WP_CLI::line( '… (body truncated, use a larger --timeout or pipe to file)' );
					}
				}
			} else {
				WP_CLI::line( '  (empty response body)' );
			}
		}

		WP_CLI::line( '' );
		WP_CLI::success( "Test complete — HTTP {$http_code} in {$elapsed}ms." );
	}

	/**
	 * Display the last N lines from the event log, similar to `tail`.
	 *
	 * Reads the events.log file and outputs the most recent entries
	 * with color-coded event types for easy scanning.
	 *
	 * ## OPTIONS
	 *
	 * [--lines=<num>]
	 * : Number of lines to show. Default 50.
	 *
	 * [--follow]
	 * : Keep the command running and stream new entries as they arrive.
	 *
	 * [--type=<type>]
	 * : Filter by event type (submit, notify, log, error, or all). Default all.
	 *
	 * [--format=<format>]
	 * : Output format — text (colorized, human-friendly) or json (machine-readable,
	 *   pipe-friendly with jq). Default: text.
	 *
	 * ## EXAMPLES
	 *
	 *     # Show the last 50 log entries.
	 *     wp crocina events tail
	 *
	 *     # Show the last 100 entries.
	 *     wp crocina events tail --lines=100
	 *
	 *     # Watch log entries in real-time.
	 *     wp crocina events tail --follow
	 *
	 *     # Show only errors.
	 *     wp crocina events tail --type=error
	 *
	 *     # Tail in JSON format for scripting / jq.
	 *     wp crocina events tail --format=json
	 *     wp crocina events tail --format=json --type=submit | jq '.[].form_id'
	 *
	 * @subcommand tail
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative flags.
	 * @return void
	 */
	public function tail( $args, $assoc_args ) {
		$log_file = $this->get_log_file_path();

		if ( ! $log_file || ! is_file( $log_file ) ) {
			WP_CLI::warning( 'Event log file not found. Make sure event logging is enabled in Settings > General.' );
			return;
		}

		$lines    = absint( $assoc_args['lines'] ?? 50 );
		$lines    = max( 1, min( $lines, 5000 ) );
		$follow   = isset( $assoc_args['follow'] ) && $assoc_args['follow'];
		$type     = isset( $assoc_args['type'] ) ? strtolower( $assoc_args['type'] ) : 'all';
		$format   = isset( $assoc_args['format'] ) ? strtolower( $assoc_args['format'] ) : 'text';

		if ( ! in_array( $format, array( 'text', 'json' ), true ) ) {
			WP_CLI::error( 'Invalid format. Use: text or json.' );
		}

		if ( $follow ) {
			$this->follow_tail( $log_file, $lines, $type, $format );
			return;
		}

		// Read entire file and grab the last N lines.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $log_file );
		if ( false === $content || '' === $content ) {
			WP_CLI::warning( 'Event log is empty.' );
			return;
		}

		// In JSON mode, parse each line into a structured object.
		if ( 'json' === $format ) {
			$this->tail_json( $content, $lines, $type );
			return;
		}

		$all_lines = explode( "\n", $content );
		// Remove trailing empty line if present.
		if ( end( $all_lines ) === '' ) {
			array_pop( $all_lines );
		}

		// Filter by type if specified.
		if ( 'all' !== $type ) {
			$type_map = $this->get_type_map();
			$needle = isset( $type_map[ $type ] ) ? $type_map[ $type ] : $type;
			$all_lines = array_values( array_filter( $all_lines, function( $line ) use ( $needle ) {
				return false !== strpos( $line, $needle );
			} ) );
		}

		$tail_lines = array_slice( $all_lines, -$lines );

		if ( empty( $tail_lines ) ) {
			WP_CLI::warning( 'No matching log entries found.' );
			return;
		}

		$total = count( $all_lines );
		$shown = count( $tail_lines );
		$label = 'all' !== $type ? " (filtered: {$type})" : '';
		WP_CLI::line( "--- Last {$shown} of {$total} event log entries{$label} ---" );
		WP_CLI::line( '' );

		foreach ( $tail_lines as $line ) {
			$this->output_line( $line );
		}

		WP_CLI::line( '' );
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Output a single log line as a compact JSON object (NDJSON-style).
	 *
	 * Parses the line and writes one JSON object per line so the output
	 * can be streamed to jq --stream or processed line-by-line in scripts.
	 *
	 * @param string $line Raw log line.
	 * @return void
	 */
	private function output_line_json( $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			return;
		}

		if ( ! preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+(\S+)\s+(\{.*\})\s*$/', $line, $m ) ) {
			// Can't parse — output as a plain text entry with raw_line.
			$fallback = array( 'raw' => $line );
			WP_CLI::line( wp_json_encode( $fallback, JSON_UNESCAPED_UNICODE ) );
			return;
		}

		$payload = json_decode( $m[3], true );
		if ( null === $payload ) {
			$payload = array( '_raw' => $m[3] );
		}

		$entry = array(
			'timestamp'  => $m[1],
			'event_name' => $m[2],
		);
		if ( is_array( $payload ) ) {
			foreach ( $payload as $k => $v ) {
				$entry[ $k ] = $v;
			}
		} else {
			$entry['payload'] = $payload;
		}

		WP_CLI::line( wp_json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Output a single log line with color coding.
	 *
	 * @param string $line Raw log line.
	 * @return void
	 */
	private function output_line( $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			return;
		}

		// Determine color based on event type.
		// Note: The event logger writes WordPress hook names (e.g. crocina_form_submitted),
		// not dot-separated PSR-14 names.
		if ( false !== strpos( $line, 'crocina_form_submitted' ) || false !== strpos( $line, 'form_after_submission' ) ) {
			// Green for form submissions.
			WP_CLI::line( WP_CLI::colorize( "%G{$line}%n" ) );
		} elseif ( false !== strpos( $line, 'notification.sent' ) ) {
			// Blue for notification events.
			WP_CLI::line( WP_CLI::colorize( "%B{$line}%n" ) );
		} elseif ( false !== strpos( $line, 'after_log_insert' ) || false !== strpos( $line, 'log.created' ) ) {
			// Magenta for log events.
			WP_CLI::line( WP_CLI::colorize( "%M{$line}%n" ) );
		} elseif ( false !== strpos( $line, '[error]' ) || false !== strpos( $line, ' error' ) ) {
			// Red for errors.
			WP_CLI::line( WP_CLI::colorize( "%R{$line}%n" ) );
		} else {
			// Default — no color.
			WP_CLI::line( $line );
		}
	}

	/**
	 * Tail in JSON mode — parse matching log lines into a JSON array.
	 *
	 * Parses each line using the same regex as the export command,
	 * applies the type filter, takes the last N entries, and outputs
	 * a compact JSON array suitable for piping into jq.
	 *
	 * @param string $content Full log file content.
	 * @param int    $lines   Number of lines to show.
	 * @param string $type    Filter type ('all' or a key from get_type_map()).
	 * @return void
	 */
	private function tail_json( $content, $lines, $type ) {
		$raw_lines = explode( "\n", $content );
		$parsed    = array();

		// Resolve type needle.
		$type_needle = '';
		if ( 'all' !== $type ) {
			$type_map = $this->get_type_map();
			$type_needle = isset( $type_map[ $type ] ) ? $type_map[ $type ] : $type;
		}

		foreach ( $raw_lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			// Apply type filter on raw line first for speed.
			if ( '' !== $type_needle && false === strpos( $line, $type_needle ) ) {
				continue;
			}

			// Parse line format: [YYYY-MM-DD HH:MM:SS] event_name  {...json...}
			if ( ! preg_match( '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+(\S+)\s+(\{.*\})\s*$/', $line, $m ) ) {
				continue;
			}

			$payload = json_decode( $m[3], true );
			if ( null === $payload ) {
				$payload = array( '_raw' => $m[3] );
			}

			$parsed[] = array(
				'timestamp'  => $m[1],
				'event_name' => $m[2],
				'payload'    => $payload,
			);
		}

		if ( empty( $parsed ) ) {
			WP_CLI::warning( 'No matching log entries found.' );
			return;
		}

		// Take only the last N.
		$tail = array_slice( $parsed, -$lines );

		// Re-shape for cleaner JSON — merge payload keys at top level.
		$output = array();
		foreach ( $tail as $entry ) {
			$row = array(
				'timestamp'  => $entry['timestamp'],
				'event_name' => $entry['event_name'],
			);
			if ( is_array( $entry['payload'] ) ) {
				foreach ( $entry['payload'] as $k => $v ) {
					$row[ $k ] = $v;
				}
			} else {
				$row['payload'] = $entry['payload'];
			}
			$output[] = $row;
		}

		// Compact JSON output — no pretty-print for pipe-friendliness.
		WP_CLI::line( wp_json_encode( $output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Follow mode: watch the log file for new entries (poll-based).
	 *
	 * When in JSON mode (--format=json), new entries are printed as
	 * individual JSON objects (one per line, NDJSON-style).
	 *
	 * @param string $log_file Full path to the log file.
	 * @param int    $lines    Number of initial lines to show.
	 * @param string $type     Filter type.
	 * @param string $format   Output format ('text' or 'json').
	 * @return void
	 */
	private function follow_tail( $log_file, $lines, $type, $format = 'text' ) {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		// Show the last N lines first.
		$content = file_get_contents( $log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false !== $content && '' !== $content ) {
			if ( 'json' === $format ) {
				$this->tail_json( $content, $lines, $type );
			} else {
				$all_lines = explode( "\n", $content );
				if ( end( $all_lines ) === '' ) {
					array_pop( $all_lines );
				}

				// Filter by type if needed.
				if ( 'all' !== $type ) {
					$type_map = array(
						'submit' => 'crocina_form_submitted',
						'notify' => 'notification.sent',
						'log'    => 'log.created',
						'error'  => 'error',
					);
					$needle = isset( $type_map[ $type ] ) ? $type_map[ $type ] : $type;
					$all_lines = array_values( array_filter( $all_lines, function( $l ) use ( $needle ) {
						return false !== strpos( $l, $needle );
					} ) );
				}

				$tail_lines = array_slice( $all_lines, -$lines );
				foreach ( $tail_lines as $line ) {
					$this->output_line( $line );
				}
			}
		}

		if ( 'json' === $format ) {
			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( '%Y--- NDJSON stream follows (Ctrl+C to stop) ---%n' ) );
		} else {
			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( "%Y--- Watching for new entries (Ctrl+C to stop) ---%n" ) );
		}

		// Poll-based follow: check file size every 2 seconds.
		$last_size = filesize( $log_file );
		$handle    = fopen( $log_file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		if ( ! $handle ) {
			WP_CLI::error( 'Unable to open log file for reading.' );
		}

		// Seek to the end minus a small buffer for partial last line.
		fseek( $handle, max( 0, $last_size - 1024 ) );
		fgets( $handle ); // Skip partial line.

		while ( true ) {
			clearstatcache( true, $log_file );
			$current_size = filesize( $log_file );

			if ( $current_size > $last_size ) {
				// New data available.
				$new_data = stream_get_contents( $handle );
				if ( false !== $new_data && '' !== $new_data ) {
					$new_lines = explode( "\n", $new_data );
					foreach ( $new_lines as $new_line ) {
						$new_line = trim( $new_line );
						if ( '' === $new_line ) {
							continue;
						}

						// Apply type filter.
						if ( 'all' !== $type ) {
							$type_map = array(
								'submit' => 'crocina_form_submitted',
								'notify' => 'notification.sent',
								'log'    => 'log.created',
								'error'  => 'error',
							);
							$needle = isset( $type_map[ $type ] ) ? $type_map[ $type ] : $type;
							if ( false === strpos( $new_line, $needle ) ) {
								continue;
							}
						}

						if ( 'json' === $format ) {
							$this->output_line_json( $new_line );
						} else {
							$this->output_line( $new_line );
						}
					}
				}
				$last_size = $current_size;
			} elseif ( $current_size < $last_size ) {
				// File was rotated — reset.
				$last_size = $current_size;
				fclose( $handle );
				$handle = fopen( $log_file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
				if ( ! $handle ) {
					break;
				}
				fseek( $handle, 0, SEEK_END );
			}

			sleep( 2 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		fclose( $handle );
	}
}

/**
 * Batch-preload all active form designs and global settings into cache.
 *
 * Uses WordPress's update_meta_cache() to load ALL form designs in a
 * single database query, then populates both the object cache (for
 * Redis/Memcached sites) and transients (for sites without a persistent
 * cache). This is the most efficient way to warm the frontend after a
 * deploy, a cache flush, or enabling a new cache backend.
 *
 * Unlike `wp crocina warm-cache` which calls warm_cache() per form and
 * updates transients individually, this command batch-loads all form
 * designs at once, reducing N+1 queries to just 2 queries total
 * (1 for settings + 1 for all designs via update_meta_cache).
 *
 * ## OPTIONS
 *
 * [--form=<ids>]
 * : Comma-separated list of form IDs to preload. Omitting preloads all published forms.
 *
 * [--quiet]
 * : Suppress progress output.
 *
 * ## EXAMPLES
 *
 *     # Preload all published forms (settings + designs).
 *     wp crocina preload
 *
 *     # Preload only specific forms.
 *     wp crocina preload --form=7,12,99
 *
 *     # Preload silently.
 *     wp crocina preload --quiet
 *
 * @when after_wp_load
 */
class Crocina_Preload_CLI extends WP_CLI_Command {

	/**
	 * Batch-preload all published forms into cache.
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative flags.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$app = Crocina_App::instance();
		/** @var Crocina_Forms_Core $core */
		$core = $app->get( 'core' );

		if ( ! $core ) {
			WP_CLI::error( 'Crocina Forms core service is not available.' );
		}

		$quiet = isset( $assoc_args['quiet'] ) && $assoc_args['quiet'];
		$persistent = wp_using_ext_object_cache();

		if ( ! $quiet ) {
			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( '%c--- Crocina Preload ---%n' ) );
			WP_CLI::line( '' );
			if ( $persistent ) {
				WP_CLI::line( 'Persistent object cache detected — populating object cache.' );
			} else {
				WP_CLI::line( 'No persistent cache detected — using transients (1-hour TTL).' );
			}
		}

		// Step 1: Preload global settings (1 query + object cache + transient).
		if ( ! $quiet ) {
			WP_CLI::line( 'Preloading global settings…' );
		}
		$core->preload_global_settings();
		if ( ! $quiet ) {
			WP_CLI::success( 'Settings cache primed.' );
		}

		// Step 2: Resolve forms.
		if ( isset( $assoc_args['form'] ) && '' !== $assoc_args['form'] ) {
			$ids = array_filter( array_map( 'absint', explode( ',', $assoc_args['form'] ) ) );
			if ( empty( $ids ) ) {
				WP_CLI::error( 'No valid form IDs provided via --form.' );
			}
			$form_posts = get_posts( array(
				'post_type'      => 'crocina_form',
				'post_status'    => 'publish',
				'post__in'       => $ids,
				'posts_per_page' => count( $ids ),
				'fields'         => 'ids',
			) );
		} else {
			$form_posts = get_posts( array(
				'post_type'      => 'crocina_form',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			) );
		}

		if ( empty( $form_posts ) ) {
			WP_CLI::warning( 'No published forms found to preload.' );
			return;
		}

		$total = count( $form_posts );

		// Step 3: Batch-preload all designs in a single meta_cache query.
		if ( ! $quiet ) {
			WP_CLI::line( "Batch-preloading {$total} form design(s)…" );
		}

		$core->preload_form_designs( $form_posts );

		// Also write transients for each design on non-cache-backed sites.
		// preload_form_designs() already handles this internally, so no extra work needed.

		if ( ! $quiet ) {
			$cache_info = $persistent ? 'object cache' : 'transients';
			WP_CLI::success( "All {$total} form design(s) primed in {$cache_info}." );

			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( '%c--- Summary ---%n' ) );
			WP_CLI::line( "  Settings: 1 query (one-time)" );
			WP_CLI::line( "  Designs:  1 query (update_meta_cache for {$total} form(s))" );
			WP_CLI::line( "  Storage:  " . ( $persistent ? 'Object cache' : "Transients ({$total} entries + 1 settings)" ) );
			WP_CLI::line( '' );
		}

		WP_CLI::success( "Preload complete — {$total} form(s) ready." );
	}
}

/**
 * List Crocina Forms and their field/design details.
 *
 * Displays all published forms in a table with their ID, title, field
 * count, field types, design cache status, and shortcode.
 *
 * ## OPTIONS
 *
 * [--format=<format>]
 * : Output format. Accepts: table, csv, json. Default: table.
 *
 * [--status=<status>]
 * : Filter by post status. Default: publish. Accepts: publish, draft, any.
 *
 * [--fields-only]
 * : Show only form ID, title, and field details (omit cache/design columns).
 *
 * ## EXAMPLES
 *
 *     # List all published forms.
 *     wp crocina form list
 *
 *     # List forms as JSON (for scripting).
 *     wp crocina form list --format=json
 *
 *     # Show draft forms too.
 *     wp crocina form list --status=any
 *
 *     # Show only fields, no cache info.
 *     wp crocina form list --fields-only
 *
 * @when after_wp_load
 */
class Crocina_Form_CLI extends WP_CLI_Command {

	/**
	 * List all forms with their field and cache details.
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative flags (--format, --status, --fields-only).
	 * @return void
	 */
	public function list_( $args, $assoc_args ) {
		$format      = $assoc_args['format'] ?? 'table';
		$status      = $assoc_args['status'] ?? 'publish';
		$fields_only = isset( $assoc_args['fields-only'] ) && $assoc_args['fields-only'];

		if ( ! in_array( $format, array( 'table', 'csv', 'json' ), true ) ) {
			WP_CLI::error( 'Invalid format. Use: table, csv, or json.' );
		}

		$allowed_statuses = array( 'publish', 'draft', 'any' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'publish';
		}

		$forms = get_posts( array(
			'post_type'      => 'crocina_form',
			'post_status'    => 'any' === $status ? array( 'publish', 'draft' ) : $status,
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );

		if ( empty( $forms ) ) {
			WP_CLI::warning( 'No forms found.' );
			return;
		}

		// Batch-preload designs so cache-status checks don't trigger DB queries.
		if ( ! $fields_only ) {
			$app = Crocina_App::instance();
			if ( $app->has( 'core' ) ) {
				$ids = wp_list_pluck( $forms, 'ID' );
				$app->get( 'core' )->preload_form_designs( $ids );
			}
		}

		$items = array();
		foreach ( $forms as $form ) {
			$fields_meta = get_post_meta( $form->ID, 'crocina_fields', true );
			$fields_meta = is_array( $fields_meta ) ? $fields_meta : array();

			$field_types = array();
			foreach ( $fields_meta as $field ) {
				$type = sanitize_key( $field['type'] ?? 'text' );
				if ( ! isset( $field_types[ $type ] ) ) {
					$field_types[ $type ] = 0;
				}
				$field_types[ $type ]++;
			}

			$field_count   = count( $fields_meta );
			$types_summary = '';
			if ( $field_count > 0 ) {
				$parts = array();
				foreach ( $field_types as $type => $count ) {
					$parts[] = "{$type}:{$count}";
				}
				$types_summary = implode( ', ', $parts );
			}

			// Check design cache status.
			$cache_status = '';
			if ( ! $fields_only ) {
				$cached = wp_cache_get( 'crocina_form_design_' . $form->ID, 'crocina_forms' );
				if ( false !== $cached ) {
					$cache_status = 'object';
				} else {
					$transient = get_transient( 'crocina_fb_design_' . $form->ID );
					if ( false !== $transient ) {
						$cache_status = 'transient';
					} else {
						$cache_status = '—';
					}
				}
			}

			$row = array(
				'ID'          => $form->ID,
				'Title'       => get_the_title( $form ),
				'Status'      => $form->post_status,
				'Fields'      => $field_count,
				'Types'       => $types_summary,
				'Shortcode'   => '[crocina_form id="' . $form->ID . '"]',
			);

			if ( ! $fields_only ) {
				$row['Design Cache'] = $cache_status;
			}

			$items[] = $row;
		}

		// Format output.
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 'csv' === $format ) {
			// Header row.
			$keys = array_keys( $items[0] );
			WP_CLI::line( implode( ',', $keys ) );
			foreach ( $items as $item ) {
				$row = array();
				foreach ( $keys as $key ) {
					$val = str_replace( '"', '""', $item[ $key ] );
					// Wrap in quotes if it contains comma or quote.
					if ( false !== strpos( $val, ',' ) || false !== strpos( $val, '"' ) ) {
						$val = '"' . $val . '"';
					}
					$row[] = $val;
				}
				WP_CLI::line( implode( ',', $row ) );
			}
			return;
		}

		// Table format (default).
		$headers = array_keys( $items[0] );
		WP_CLI\Utils\format_items( 'table', $items, $headers );

		// Summary footer.
		$total_fields = array_sum( wp_list_pluck( $items, 'Fields' ) );
		WP_CLI::line( '' );
		WP_CLI::line( "Total forms: " . count( $items ) . " | Total fields: {$total_fields}" );

		if ( ! $fields_only ) {
			$cached_count = 0;
			foreach ( $items as $item ) {
				if ( isset( $item['Design Cache'] ) && '—' !== $item['Design Cache'] ) {
					$cached_count++;
				}
			}
			WP_CLI::line( "Designs cached: {$cached_count} / " . count( $items ) );
		}
	}
}

/**
 * Test webhook endpoints for Crocina Forms.
 *
 * Sends a sample form submission payload to a specified URL so you can
 * verify that your webhook endpoint (Zapier, n8n, Make, or custom) is
 * configured correctly before going live.
 *
 * ## OPTIONS
 *
 * <url>
 * : The webhook endpoint URL to test.
 *
 * [--form=<id>]
 * : Form ID whose fields and structure will be used for the sample payload.
 *   Omit to use generic sample data.
 *
 * [--fields=<key=value,...>]
 * : Custom field values for the payload, comma-separated (e.g.
 *   "email=test@example.com,name=John"). Overrides auto-generated values.
 *
 * [--verbose]
 * : Show full response headers and body.
 *
 * ## EXAMPLES
 *
 *     # Test a webhook with generic sample data.
 *     wp crocina webhook test https://hooks.zapier.com/hooks/catch/abc123/
 *
 *     # Test using a real form's structure.
 *     wp crocina webhook test https://example.com/webhook --form=5
 *
 *     # Test with custom field values.
 *     wp crocina webhook test https://example.com/webhook --fields="name=Ali,email=ali@test.com"
 *
 *     # Show full response body.
 *     wp crocina webhook test https://example.com/webhook --verbose
 *
 * @when after_wp_load
 */
class Crocina_Webhook_CLI extends WP_CLI_Command {

	/**
	 * Send a test payload to a webhook endpoint.
	 *
	 * @subcommand test
	 *
	 * @param array $args       Positional arguments. First arg is the URL.
	 * @param array $assoc_args Associative flags (--form, --fields, --verbose).
	 * @return void
	 */
	public function test( $args, $assoc_args ) {
		$url = $args[0] ?? '';
		if ( empty( $url ) ) {
			WP_CLI::error( 'Please provide a webhook URL as the first argument.' );
		}

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			WP_CLI::error( 'Invalid URL provided.' );
		}

		$form_id    = absint( $assoc_args['form'] ?? 0 );
		$verbose    = isset( $assoc_args['verbose'] ) && $assoc_args['verbose'];
		$custom_raw = $assoc_args['fields'] ?? '';

		// Parse custom field overrides.
		$custom_fields = array();
		if ( '' !== $custom_raw ) {
			foreach ( explode( ',', $custom_raw ) as $pair ) {
				$pair = trim( $pair );
				if ( false !== strpos( $pair, '=' ) ) {
					list( $key, $value ) = explode( '=', $pair, 2 );
					$custom_fields[ trim( $key ) ] = trim( $value );
				}
			}
		}

		// Build the sample payload.
		$payload = $this->build_sample_payload( $form_id, $custom_fields );

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%c--- Crocina Webhook Test ---%n' ) );
		WP_CLI::line( "Target URL: {$url}" );
		if ( $form_id ) {
			WP_CLI::line( "Form ID:    #{$form_id}" );
		}
		WP_CLI::line( '' );

		// Send the payload.
		WP_CLI::line( 'Sending test payload…' );

		// Build a sample payload that matches the webhook's expected format.
		$payload_json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

		$args = array(
			'method'      => 'POST',
			'timeout'     => 15,
			'redirection' => 5,
			'httpversion' => '1.1',
			'blocking'    => true,
			'headers'     => array(
				'Content-Type' => 'application/json',
				'User-Agent'   => 'Crocina Forms Webhook Tester/1.0',
			),
			'body'        => $payload_json,
		);

		$start = microtime( true );
		$response = wp_remote_post( $url, $args );
		$elapsed = round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			WP_CLI::error( 'HTTP request failed: ' . $response->get_error_message() );
			return;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = wp_remote_retrieve_body( $response );
		$headers   = wp_remote_retrieve_headers( $response );

		WP_CLI::line( '' );

		// Determine status.
		$is_success = $http_code >= 200 && $http_code < 300;
		$status_icon = $is_success ? '%G✓%n' : '%R✗%n';
		WP_CLI::line( WP_CLI::colorize( "{$status_icon} HTTP {$http_code} ({$elapsed}ms)" ) );

		if ( $is_success ) {
			WP_CLI::success( 'Webhook delivered successfully.' );
		} else {
			WP_CLI::warning( "Webhook returned HTTP {$http_code}. Check your endpoint." );
		}

		if ( $verbose ) {
			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( '%c--- Response Headers ---%n' ) );
			if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
				foreach ( $headers->getAll() as $key => $value ) {
					WP_CLI::line( "  {$key}: " . ( is_array( $value ) ? implode( ', ', $value ) : $value ) );
				}
			}

			WP_CLI::line( '' );
			WP_CLI::line( WP_CLI::colorize( '%c--- Response Body ---%n' ) );
			if ( '' !== $body ) {
				// Try to format JSON response nicely.
				$decoded = json_decode( $body, true );
				if ( is_array( $decoded ) ) {
					WP_CLI::line( wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
				} else {
					WP_CLI::line( substr( $body, 0, 2000 ) );
					if ( strlen( $body ) > 2000 ) {
						WP_CLI::line( '… (body truncated, use --verbose for full output)' );
					}
				}
			} else {
				WP_CLI::line( '(empty)' );
			}
		}

		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( '%c--- Sent Payload ---%n' ) );
		// Count keys in payload for a compact summary.
		$field_count = count( $payload['fields'] ?? array() );
		$payload_size = strlen( $payload_json );
		WP_CLI::line( "Fields: {$field_count}  |  Payload size: {$payload_size} bytes" );
		if ( $verbose ) {
			WP_CLI::line( $payload_json );
		}

		WP_CLI::line( '' );
		if ( $is_success ) {
			WP_CLI::success( "Test complete — HTTP {$http_code} in {$elapsed}ms ({$payload_size} bytes sent)." );
		} else {
			WP_CLI::warning( "Test complete — HTTP {$http_code} in {$elapsed}ms. Review your endpoint configuration." );
		}
	}

	/**
	 * Build a sample submission payload for testing.
	 *
	 * @param int   $form_id      Optional form ID to pull field structure from.
	 * @param array $custom_fields Associative array of field key => value overrides.
	 * @return array
	 */
	private function build_sample_payload( $form_id = 0, $custom_fields = array() ) {
		// Default sample fields.
		$sample_fields = array(
			array( 'slug' => 'name',    'label' => 'Full Name', 'value' => 'John Doe' ),
			array( 'slug' => 'email',   'label' => 'Email',     'value' => 'john@example.com' ),
			array( 'slug' => 'message', 'label' => 'Message',   'value' => 'This is a test submission from the Crocina Forms webhook tester.' ),
		);

		// If a form ID is provided, use its actual field structure.
		if ( $form_id ) {
			$form   = get_post( $form_id );
			$fields = get_post_meta( $form_id, 'crocina_fields', true );
			if ( $form && is_array( $fields ) && ! empty( $fields ) ) {
				$sample_fields = array();
				foreach ( $fields as $field ) {
					$slug  = sanitize_key( $field['slug'] ?? $field['label'] ?? 'field' );
					$label = sanitize_text_field( $field['label'] ?? $slug );
					$type  = sanitize_key( $field['type'] ?? 'text' );

					// Generate a sample value based on field type.
					$value = $this->sample_value_for_type( $type, $label, $slug );

					// Apply custom override if provided.
					if ( isset( $custom_fields[ $slug ] ) ) {
						$value = $custom_fields[ $slug ];
					} elseif ( isset( $custom_fields[ $label ] ) ) {
						$value = $custom_fields[ $label ];
					}

					$sample_fields[] = array(
						'slug'  => $slug,
						'label' => $label,
						'value' => $value,
					);
				}
			} else {
				WP_CLI::warning( "Form #{$form_id} not found or has no fields. Using generic sample data." );
			}
		} else {
			// Apply custom overrides when no form is specified.
			foreach ( $sample_fields as &$sf ) {
				if ( isset( $custom_fields[ $sf['slug'] ] ) ) {
					$sf['value'] = $custom_fields[ $sf['slug'] ];
				} elseif ( isset( $custom_fields[ $sf['label'] ] ) ) {
					$sf['value'] = $custom_fields[ $sf['label'] ];
				}
			}
			unset( $sf );
		}

		$now_utc   = gmdate( 'Y-m-d H:i:s' );
		$gmt_off   = (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
		$now_local = gmdate( 'Y-m-d H:i:s', time() + $gmt_off );

		/** @var Crocina_Forms_Core $core */
		$core = null;
		if ( class_exists( 'Crocina_App' ) ) {
			$app = Crocina_App::instance();
			if ( $app->has( 'core' ) ) {
				$core = $app->get( 'core' );
			}
		}

		return array(
			'form_id'             => $form_id,
			'page_title'          => $form_id ? ( get_the_title( $form_id ) ?: 'Test Form' ) . ' (test)' : 'Contact Form (test)',
			'page_url'            => $form_id ? get_permalink( $form_id ) ?: home_url() : home_url(),
			'submitted_at'        => $now_utc,
			'submitted_at_jalali' => $core ? $core->format_jalali_display( time() + $gmt_off, 'full' ) : $now_local,
			'user_ip'             => '127.0.0.1',
			'fields'              => $sample_fields,
			'is_test'             => true,
		);
	}

	/**
	 * Generate a realistic sample value based on field type.
	 *
	 * @param string $type  Field type (text, email, tel, number, etc.).
	 * @param string $label Field label for smart defaults.
	 * @param string $slug  Field slug.
	 * @return string
	 */
	private function sample_value_for_type( $type, $label, $slug ) {
		$label_lower = strtolower( $label );
		$slug_lower  = strtolower( $slug );

		// Smart defaults based on label/slug keywords.
		if ( false !== strpos( $label_lower, 'name' ) || false !== strpos( $slug_lower, 'name' ) ) {
			return 'John Doe';
		}
		if ( false !== strpos( $label_lower, 'email' ) || false !== strpos( $slug_lower, 'email' ) ) {
			return 'john@example.com';
		}
		if ( false !== strpos( $label_lower, 'phone' ) || false !== strpos( $slug_lower, 'phone' ) || false !== strpos( $label_lower, 'tel' ) || false !== strpos( $slug_lower, 'tel' ) ) {
			return '+1 (555) 123-4567';
		}
		if ( false !== strpos( $label_lower, 'message' ) || false !== strpos( $slug_lower, 'message' ) || false !== strpos( $label_lower, 'comment' ) || false !== strpos( $slug_lower, 'comment' ) ) {
			return 'This is a test message from the Crocina webhook tester.';
		}
		if ( false !== strpos( $label_lower, 'url' ) || false !== strpos( $slug_lower, 'url' ) || false !== strpos( $label_lower, 'website' ) || false !== strpos( $slug_lower, 'website' ) ) {
			return 'https://example.com';
		}
		if ( false !== strpos( $label_lower, 'number' ) || false !== strpos( $slug_lower, 'number' ) || false !== strpos( $label_lower, 'age' ) || false !== strpos( $slug_lower, 'age' ) ) {
			return '42';
		}

		// Fallback by type.
		switch ( $type ) {
			case 'email':
				return 'user@example.com';
			case 'tel':
				return '+1 (555) 123-4567';
			case 'number':
				return '42';
			case 'url':
				return 'https://example.com';
			case 'select':
				return 'Option 1';
			case 'radio':
				return 'Yes';
			case 'checkbox':
				return 'Yes';
			case 'textarea':
				return 'Sample text content for testing purposes.';
			default:
				return 'Sample value';
		}
	}
}

WP_CLI::add_command( 'crocina cache', 'Crocina_CLI' );
WP_CLI::add_command( 'crocina events', 'Crocina_Events_CLI' );
WP_CLI::add_command( 'crocina form', 'Crocina_Form_CLI' );
WP_CLI::add_command( 'crocina warm-cache', 'Crocina_WarmCache_CLI' );
WP_CLI::add_command( 'crocina preload', 'Crocina_Preload_CLI' );
WP_CLI::add_command( 'crocina webhook', 'Crocina_Webhook_CLI' );
