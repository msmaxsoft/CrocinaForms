<?php
/**
 * WP-CLI command: wp crocina security check
 *
 * Scans all wp_ajax_* and wp_ajax_nopriv_* handlers registered by the
 * plugin and reports whether each handler implements:
 *
 *   - Nonce verification (check_ajax_referer)
 *   - Capability check (current_user_can)
 *   - Fallback wp_die / wp_send_json_error guards
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Inspect AJAX handlers for common security patterns.
 */
class Crocina_Security_CLI extends WP_CLI_Command {

	/**
	 * Scan every AJAX endpoint registered by Crocina Forms and report
	 * whether it implements nonce checking, capability checking, and
	 * proper error guards.
	 *
	 * For wp_ajax_nopriv_* (public) endpoints, capability is marked
	 * as N/A because these endpoints serve unauthenticated users.
	 *
	 * ## EXAMPLES
	 *
	 *     # Run a full security audit of all AJAX handlers.
	 *     wp crocina security check
	 *
	 *     # Show only endpoints that FAIL validation.
	 *     wp crocina security check --failures-only
	 *
	 *     # Output as JSON for machine processing.
	 *     wp crocina security check --format=json
	 *
	 * @subcommand check
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative flags.
	 * @return void
	 */
	public function check( $args, $assoc_args ) {
		global $wp_filter;

		if ( ! $wp_filter || ! is_array( $wp_filter ) ) {
			WP_CLI::error( 'WordPress hooks are not available. Run this command in a WP-CLI environment after WordPress has loaded.' );
			return;
		}

		$failures_only = ! empty( $assoc_args['failures-only'] );
		$format        = $assoc_args['format'] ?? 'table';

		// Collect all wp_ajax_* hooks (including wp_ajax_nopriv_*).
		$endpoints = array();
		foreach ( $wp_filter as $hook_name => $hook_obj ) {
			if ( 0 !== strpos( $hook_name, 'wp_ajax_' ) ) {
				continue;
			}
			$endpoints[ $hook_name ] = array();
			foreach ( $hook_obj->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $idx => $cb_data ) {
					$endpoints[ $hook_name ][] = array(
						'callback' => $cb_data['function'],
						'priority' => $priority,
					);
				}
			}
		}

		if ( empty( $endpoints ) ) {
			WP_CLI::warning( 'No AJAX endpoints found. Ensure WordPress is fully loaded.' );
			return;
		}

		$results = array();
		foreach ( $endpoints as $hook => $handlers ) {
			foreach ( $handlers as $handler ) {
				$result = $this->inspect_handler( $hook, $handler );
			if ( $failures_only && ( $result['capability_na'] ? $result['score'] >= 2 : $result['score'] >= 3 ) ) {
			continue;
		}
				$results[] = $result;
			}
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		if ( empty( $results ) ) {
			WP_CLI::success( 'All endpoints passed security checks.' );
			return;
		}

		// Build display table.
		$table   = array();
		$summary = array(
			'pass'    => 0,
			'warn'    => 0,
			'fail'    => 0,
			'unknown' => 0,
		);

		foreach ( $results as $r ) {
			$max_score   = $r['capability_na'] ? 2 : 3;
			$status_icon = $this->status_icon( $r['score'], $r['capability_na'] );
			$summary[ $status_icon['status'] ]++;

			$table[] = array(
				'Endpoint'   => $r['hook'],
				'Handler'    => $r['handler'],
				'Nonce'      => $r['nonce'] ? 'PASS' : ( $r['nonce_warning'] ? 'WARN' : 'FAIL' ),
				'Capability' => $r['capability_na'] ? 'N/A' : ( $r['capability'] ? 'PASS' : ( $r['capability_warning'] ? 'WARN' : 'FAIL' ) ),
				'Guard'      => $r['guard'] ? 'PASS' : 'FAIL',
				'Score'      => $r['score'] . '/' . $max_score,
				'Notes'      => $r['notes'],
			);
		}

		WP_CLI\Utils\format_items( $format, $table, array( 'Endpoint', 'Handler', 'Nonce', 'Capability', 'Guard', 'Score', 'Notes' ) );

		WP_CLI::line( '' );
		WP_CLI::line( "Summary: {$summary['pass']} pass, {$summary['warn']} warn, {$summary['fail']} fail, {$summary['unknown']} unknown" );

		if ( $summary['fail'] > 0 || $summary['warn'] > 0 ) {
			WP_CLI::warning( 'Some endpoints have security gaps. Review the FAIL/WARN rows above.' );
		} else {
			WP_CLI::success( 'All endpoints pass security checks.' );
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Inspection helpers                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Inspect a single AJAX callback for security patterns.
	 *
	 * @param string $hook    Hook name (e.g. wp_ajax_crocina_submit_form).
	 * @param array  $handler { callback, priority }.
	 * @return array{hook:string,handler:string,nonce:bool,nonce_warning:bool,capability:bool,capability_warning:bool,capability_na:bool,guard:bool,score:int,notes:string}
	 */
	private function inspect_handler( $hook, $handler ) {
		$is_nopriv = false !== strpos( $hook, 'wp_ajax_nopriv_' );

		$result = array(
			'hook'               => $hook,
			'handler'            => $this->format_callback( $handler['callback'] ),
			'nonce'              => false,
			'nonce_warning'      => false,
			'capability'         => false,
			'capability_warning' => false,
			'capability_na'      => $is_nopriv,
			'guard'              => false,
			'score'              => 0,
			'notes'              => '',
		);

		$source = $this->resolve_callback_source( $handler['callback'] );
		if ( ! $source ) {
			$result['notes'] = 'Could not resolve callback source.';
			return $result;
		}

		$body = $this->extract_method_body( $source['file'], $source['start_line'], $source['end_line'] );
		if ( null === $body ) {
			$result['notes'] = 'Could not read method body.';
			return $result;
		}

		$normalised = preg_replace( '/\s+/', ' ', $body );

		// 1. Nonce check.
		if ( preg_match( '/check_ajax_referer\s*\(/', $normalised ) ) {
			$result['nonce']  = true;
			$result['score'] += 1;
		} elseif ( preg_match( '/wp_verify_nonce\s*\(/', $normalised ) ) {
			$result['nonce_warning'] = true;
			$result['score']       += 1;
			$result['notes']        = 'Uses wp_verify_nonce instead of check_ajax_referer.';
		}

		// 2. Capability check (skipped for nopriv — user is not logged in).
		if ( ! $is_nopriv ) {
			if ( preg_match( '/current_user_can\s*\(/', $normalised ) ) {
				$result['capability'] = true;
				$result['score']     += 1;
			} elseif ( preg_match( '/wp_die\s*\(\s*[\'"][^"\']*(not allowed|permission|forbidden|unable)[^"\']*[\'"]/', $normalised ) ) {
				$result['capability_warning'] = true;
				$result['score']            += 1;
				$result['notes']            .= ( $result['notes'] ? ' ' : '' ) . 'Uses wp_die message fallback.';
			}
		}

		// 3. Error guard (wp_die|wp_send_json_error after failed checks).
		if ( preg_match( '/wp_(die|send_json_error|redirect)/', $normalised ) ) {
			$result['guard']  = true;
			$result['score'] += 1;
		}

		return $result;
	}

	/**
	 * Resolve a callback to its source file and line range.
	 *
	 * @param callable|array|string $callback The callback to resolve.
	 * @return array{file:string,start_line:int,end_line:int}|null
	 */
	private function resolve_callback_source( $callback ) {
		if ( is_array( $callback ) && count( $callback ) === 2 ) {
			try {
				if ( is_object( $callback[0] ) ) {
					$ref = new ReflectionMethod( $callback[0], $callback[1] );
				} elseif ( is_string( $callback[0] ) ) {
					$ref = new ReflectionMethod( $callback[0], $callback[1] );
				} else {
					return null;
				}
			} catch ( ReflectionException $e ) {
				return null;
			}

			$file = $ref->getFileName();
			if ( ! $file || ! file_exists( $file ) ) {
				return null;
			}

			return array(
				'file'       => $file,
				'start_line' => $ref->getStartLine(),
				'end_line'   => $ref->getEndLine(),
			);
		}

		if ( is_string( $callback ) && function_exists( $callback ) ) {
			try {
				$ref = new ReflectionFunction( $callback );
			} catch ( ReflectionException $e ) {
				return null;
			}
			return array(
				'file'       => $ref->getFileName(),
				'start_line' => $ref->getStartLine(),
				'end_line'   => $ref->getEndLine(),
			);
		}

		if ( $callback instanceof Closure ) {
			try {
				$ref = new ReflectionFunction( $callback );
			} catch ( ReflectionException $e ) {
				return null;
			}
			return array(
				'file'       => $ref->getFileName(),
				'start_line' => $ref->getStartLine(),
				'end_line'   => $ref->getEndLine(),
			);
		}

		return null;
	}

	/**
	 * Extract a method/function body from source lines.
	 *
	 * @param string $file       Absolute file path.
	 * @param int    $start_line Starting line.
	 * @param int    $end_line   Ending line.
	 * @return string|null
	 */
	private function extract_method_body( $file, $start_line, $end_line ) {
		if ( ! $file || ! file_exists( $file ) || ! is_readable( $file ) ) {
			return null;
		}

		$lines = file( $file, FILE_IGNORE_NEW_LINES );
		if ( false === $lines ) {
			return null;
		}

		$length = $end_line - $start_line + 1;
		$body   = array_slice( $lines, $start_line - 1, $length );

		return implode( "\n", $body );
	}

	/**
	 * Format a callback into a human-readable string.
	 *
	 * @param callable|array|string $callback The callback.
	 * @return string
	 */
	private function format_callback( $callback ) {
		if ( is_array( $callback ) && count( $callback ) === 2 ) {
			if ( is_object( $callback[0] ) ) {
				return get_class( $callback[0] ) . '::' . $callback[1];
			}
			return $callback[0] . '::' . $callback[1];
		}
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( $callback instanceof Closure ) {
			return 'Closure';
		}
		return 'unknown';
	}

	/**
	 * Map a numeric score to a status category.
	 *
	 * @param int $score 0-3.
	 * @return array{icon:string,status:string}
	 */
	private function status_icon( $score ) {
		if ( $score >= 3 ) {
			return array( 'icon' => "\xE2\x9C\x85", 'status' => 'pass' );
		}
		if ( $score >= 2 ) {
			return array( 'icon' => "\xE2\x9A\xA0", 'status' => 'warn' );
		}
		if ( $score >= 1 ) {
			return array( 'icon' => "\xE2\x9D\x8C", 'status' => 'fail' );
		}
		return array( 'icon' => "\xE2\x9D\x93", 'status' => 'unknown' );
	}
}

WP_CLI::add_command( 'crocina security', 'Crocina_Security_CLI' );
