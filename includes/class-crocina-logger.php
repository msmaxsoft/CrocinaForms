<?php

defined( 'ABSPATH' ) || exit;

class Crocina_Logger implements Crocina_Logger_Interface {
	private const DB_VERSION = '1.5';

	/**
	 * Application container.
	 *
	 * @var Crocina_App|null
	 */
	private $app;

	/**
	 * Per-request cache of table-existence checks, keyed by full table name.
	 *
	 * @var array<string,bool>
	 */
	private static $table_exists_cache = array();

	/**
	 * @param Crocina_App|null $app Optional — allows event dispatching.
	 */
	public function __construct( $app = null ) {
		$this->app = $app;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Logger is mostly called imperatively; no WordPress hooks needed
		// beyond the upgrade check which is triggered by Crocina_Forms_Core.
	}

	/**
	 * @return void
	 */
	public function install_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'crocina_form_logs';
		$charset    = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id bigint(20) unsigned NOT NULL,
			submitted_at datetime NOT NULL,
			is_read tinyint(1) NOT NULL DEFAULT 0,
			user_ip varchar(45) NOT NULL DEFAULT '',
			user_agent text,
			page_url text NOT NULL,
			page_title varchar(255) DEFAULT '',
			payload longtext NOT NULL,
			PRIMARY KEY  (id),
			KEY submitted_at (submitted_at),
			KEY form_submitted (form_id, submitted_at),
			KEY form_read (form_id, is_read),
			FULLTEXT KEY search_payload (payload)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$prev_version = get_option( 'crocina_logs_db_version', '0.0' );
		update_option( 'crocina_logs_db_version', self::DB_VERSION );

		if ( '0.0' === $prev_version ) {
			self::log_migration( sprintf(
				'Logs table created at v%s.',
				self::DB_VERSION
			) );
		} else {
			self::log_migration( sprintf(
				'Logs table upgraded from v%s to v%s.',
				$prev_version,
				self::DB_VERSION
			) );
		}

		self::set_table_exists_cache( $table_name, true );
	}

	/**
	 * @return void
	 */
	public function maybe_upgrade() {
		$current = get_option( 'crocina_logs_db_version', '1.0' );
		if ( version_compare( $current, self::DB_VERSION, '<' ) ) {
			// Always call install_table() first — dbDelta handles CREATE / ALTER for
			// indexes that DO exist in the schema, but it NEVER drops indexes.
			$this->install_table();

			// Remove legacy single-column indexes that were replaced by compound indexes.
			// dbDelta won't drop them, so we do it manually.
			// This runs on EVERY upgrade because even sites at version 1.4 may still
			// have the old indexes (the 1.4 schema change couldn't remove them).
			// The method is idempotent — it checks existence before dropping.
			$this->cleanup_legacy_indexes();
		}
	}

	/**
	 * @param int   $form_id
	 * @param array $payload
	 * @return int
	 */
	public function log( $form_id, $payload ) {
		global $wpdb;

		$table = $wpdb->prefix . 'crocina_form_logs';
		if ( ! self::table_exists( $table ) ) {
			$this->install_table();
		}

		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$submitted_at = $payload['submitted_at'] ?? gmdate( 'Y-m-d H:i:s', time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
		$log_data = array(
			'form_id'      => absint( $form_id ),
			'submitted_at' => $submitted_at,
			'user_ip'      => sanitize_text_field( $payload['user_ip'] ?? '' ),
			'user_agent'   => $user_agent,
			'page_url'     => esc_url_raw( $payload['page_url'] ?? '' ),
			'page_title'   => sanitize_text_field( $payload['page_title'] ?? '' ),
			'payload'      => $payload,
		);

		$log_data = apply_filters( 'crocina_before_log_insert', $log_data, $form_id );

		$payload_json = $this->prepare_payload_json( $log_data['payload'] );

		$result = $wpdb->insert(
			$table,
			array(				'form_id'      => $log_data['form_id'],
				'submitted_at' => $log_data['submitted_at'],
				'is_read'      => 0,
				'user_ip'      => $log_data['user_ip'],
				'user_agent'   => $log_data['user_agent'],
				'page_url'     => $log_data['page_url'],
				'page_title'   => $log_data['page_title'],
				'payload'      => $payload_json,
			),
			array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Crocina Logger: Failed to insert log. Error: ' . $wpdb->last_error );
		}

		if ( $result ) {
			$log_id = (int) $wpdb->insert_id;

			// Sync payload_ft if the fallback TEXT column exists.
			$this->sync_payload_ft( $log_id, $payload_json );

			// Dispatch event through the Event Dispatcher if available.
			if ( $this->app && $this->app->has( 'events' ) ) {
				$dispatcher = $this->app->get( 'events' );
				if ( $dispatcher && method_exists( $dispatcher, 'dispatch' ) ) {
					$dispatcher->dispatch( 'log.created', array(
						'log_id'   => $log_id,
						'form_id'  => $form_id,
						'log_data' => $log_data,
					) );
				}
			}

			// Also fire the WordPress action for backward compatibility.
			do_action( 'crocina_after_log_insert', $log_id, $log_data, $form_id );
		}

		return $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param int $log_id
	 * @return object|null
	 */
	public function get_log( $log_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'crocina_form_logs';
		if ( ! self::table_exists( $table ) ) {
			return null;
		}
		$log_id = absint( $log_id );
		if ( ! $log_id ) {
			return null;
		}
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $log_id );
		return $wpdb->get_row( $sql );
	}

	/**
	 * @param int $days
	 * @return void
	 */
	public function prune( $days ) {
		global $wpdb;
		$table = $wpdb->prefix . 'crocina_form_logs';
		if ( ! self::table_exists( $table ) ) {
			return;
		}

		$now_local = time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		$cutoff    = gmdate( 'Y-m-d H:i:s', $now_local - DAY_IN_SECONDS * max( 1, $days ) );
		$limit     = 1000;

		do {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE submitted_at < %s ORDER BY submitted_at LIMIT %d",
					$cutoff,
					$limit
				)
			);
			if ( false === $deleted || $deleted < $limit ) {
				break;
			}
			usleep( 100000 );
		} while ( $deleted === $limit );
	}

	/**
	 * @param int $form_id
	 * @return int
	 */
	public function prune_form( $form_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'crocina_form_logs';
		if ( ! self::table_exists( $table ) ) {
			return 0;
		}
		$form_id = absint( $form_id );
		if ( ! $form_id ) {
			return 0;
		}
		$result = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE form_id = %d", $form_id )
		);
		return $result ? (int) $result : 0;
	}

	/**
	 * @param array $args
	 * @return array
	 */
	public function get_logs( $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'crocina_form_logs';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$defaults = array(
			'form_id'  => 0,
			'per_page' => 20,
			'page'     => 1,
			'orderby'  => 'submitted_at',
			'order'    => 'DESC',
			'is_read'  => '', // '' = all, '0' = unread, '1' = read
		);
		$args = wp_parse_args( $args, $defaults );

		$per_page = absint( $args['per_page'] );
		if ( $per_page <= 0 ) {
			$per_page = 500;
		}
		$page     = max( 1, absint( $args['page'] ) );
		$offset   = $per_page ? ( $page - 1 ) * $per_page : 0;

		$where_parts = array();
		$params       = array();
		if ( $args['form_id'] ) {
			$where_parts[] = 'form_id = %d';
			$params[]     = $args['form_id'];
		}
		if ( '' !== $args['is_read'] ) {
			$where_parts[] = 'is_read = %d';
			$params[]     = absint( $args['is_read'] );
		}

		$where_clause = $where_parts ? 'WHERE ' . implode( ' AND ', $where_parts ) : '';

		$allowed_orderby = array( 'submitted_at', 'form_id', 'user_ip' );
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'submitted_at';
		$order = strtoupper( $args['order'] );
		if ( 'ASC' !== $order ) {
			$order = 'DESC';
		}

		$query = "SELECT * FROM {$table} {$where_clause} ORDER BY {$orderby} {$order}";
		$sql   = $params ? $wpdb->prepare( $query, $params ) : $query;

		if ( $per_page ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, $offset );
		}

		return $wpdb->get_results( $sql );
	}

	/**
	 * @param int $log_id
	 * @return int
	 */
	public function delete_log( $log_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'crocina_form_logs';
		if ( ! self::table_exists( $table ) ) {
			return 0;
		}
		$log_id = absint( $log_id );
		if ( ! $log_id ) {
			return 0;
		}
		$result = $wpdb->delete( $table, array( 'id' => $log_id ), array( '%d' ) );
		return $result ? (int) $result : 0;
	}

	/**
	 * @param string $search
	 * @param array  $args
	 * @return array
	 */
	public function search_logs( $search, $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'crocina_form_logs';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$defaults = array(
			'form_id'  => 0,
			'per_page' => 20,
			'page'     => 1,
			'orderby'  => 'submitted_at',
			'order'    => 'DESC',
			'is_read'  => '', // '' = all, '0' = unread, '1' = read
		);
		$args = wp_parse_args( $args, $defaults );

		$search = (string) $search;
		if ( '' === $search ) {
			return array();
		}

		$per_page = max( 0, absint( $args['per_page'] ) );
		$page     = max( 1, absint( $args['page'] ) );
		$offset   = $per_page ? ( $page - 1 ) * $per_page : 0;

		$allowed_orderby = array( 'submitted_at', 'form_id', 'user_ip' );
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'submitted_at';
		$order = strtoupper( $args['order'] );
		if ( 'ASC' !== $order ) {
			$order = 'DESC';
		}

		$where = array();
		$params = array();

		if ( $args['form_id'] ) {
			$where[] = 'form_id = %d';
			$params[] = $args['form_id'];
		}
		if ( '' !== $args['is_read'] ) {
			$where[] = 'is_read = %d';
			$params[] = absint( $args['is_read'] );
		}

		$clause = $this->build_payload_search_clause( $search );
		$where[] = $clause['where'];
		$params  = array_merge( $params, $clause['params'] );

		$where_clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$query = "SELECT * FROM {$table} {$where_clause} ORDER BY {$orderby} {$order}";
		$sql = $wpdb->prepare( $query, $params );

		if ( $per_page ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, $offset );
		}

		return $wpdb->get_results( $sql );
	}

	/**
	 * @param string $search
	 * @param int    $form_id
	 * @param string $is_read  '' = all, '0' = unread, '1' = read
	 * @return int
	 */
	public function search_logs_count( $search, $form_id = 0, $is_read = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'crocina_form_logs';
		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		$search = (string) $search;
		if ( '' === $search ) {
			return 0;
		}

		$where = array();
		$params = array();

		if ( $form_id ) {
			$where[] = 'form_id = %d';
			$params[] = absint( $form_id );
		}
		if ( '' !== $is_read ) {
			$where[] = 'is_read = %d';
			$params[] = absint( $is_read );
		}

		$clause = $this->build_payload_search_clause( $search );
		$where[] = $clause['where'];
		$params  = array_merge( $params, $clause['params'] );

		$where_clause = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$sql = "SELECT COUNT(*) FROM {$table} {$where_clause}";

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * @param int    $form_id
	 * @param string $is_read  '' = all (default), '0' = unread only, '1' = read only
	 * @return int
	 */
	public function get_logs_count( $form_id = 0, $is_read = '' ) {
		global $wpdb;
		$table = $wpdb->prefix . 'crocina_form_logs';
		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		$cache_key = 'crocina_logs_count_' . ( $form_id ? absint( $form_id ) : 'total' ) . '_' . $is_read;
		$cached    = wp_cache_get( $cache_key, 'crocina_forms' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$where_parts = array();
		$params      = array();
		if ( $form_id ) {
			$where_parts[] = 'form_id = %d';
			$params[] = $form_id;
		}
		if ( '' !== $is_read ) {
			$where_parts[] = 'is_read = %d';
			$params[] = absint( $is_read );
		}

		$where_sql = $where_parts ? 'WHERE ' . implode( ' AND ', $where_parts ) : '';
		$sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";

		if ( $params ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		} else {
			$count = (int) $wpdb->get_var( $sql );
		}

		wp_cache_set( $cache_key, $count, 'crocina_forms', 300 ); // 5-minute TTL

		return $count;
	}

	/**
	 * @param int $form_id
	 * @return int
	 */
	public function get_unread_count( $form_id = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'crocina_form_logs';
		if ( ! self::table_exists( $table ) ) {
			return 0;
		}
		if ( $form_id ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND is_read = 0", $form_id )
			);
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_read = 0" );
	}

	/**
	 * @param int $log_id
	 * @return bool
	 */
	public function mark_read( $log_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'crocina_form_logs';
		if ( ! self::table_exists( $table ) ) {
			return false;
		}
		$log_id = absint( $log_id );
		if ( ! $log_id ) {
			return false;
		}
		$result = $wpdb->update(
			$table,
			array( 'is_read' => 1 ),
			array( 'id' => $log_id ),
			array( '%d' ),
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * @param int $log_id
	 * @return bool
	 */
	public function mark_unread( $log_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'crocina_form_logs';
		if ( ! self::table_exists( $table ) ) {
			return false;
		}
		$log_id = absint( $log_id );
		if ( ! $log_id ) {
			return false;
		}
		$result = $wpdb->update(
			$table,
			array( 'is_read' => 0 ),
			array( 'id' => $log_id ),
			array( '%d' ),
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * Mark all logs for a given form as read.
	 *
	 * @param int $form_id
	 * @return int Number of rows updated.
	 */
	public function mark_all_read( $form_id = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'crocina_form_logs';
		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		if ( $form_id ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET is_read = 1 WHERE form_id = %d AND is_read = 0",
					absint( $form_id )
				)
			);
		} else {
			$result = $wpdb->query(
				"UPDATE {$table} SET is_read = 1 WHERE is_read = 0"
			);
		}

		// Invalidate count caches.
		// Keys follow the format: crocina_logs_count_{form_id}_{is_read}
		// where is_read='' (all), '0' (unread), or '1' (read).
		// We delete all possible variants plus the old-format key for BC.
		$cache_prefix = 'crocina_logs_count_';
		if ( $form_id ) {
			$fid = absint( $form_id );
			wp_cache_delete( $cache_prefix . $fid,        'crocina_forms' ); // old format (no trailing _)
			wp_cache_delete( $cache_prefix . $fid . '_',  'crocina_forms' ); // new format, is_read=''
			wp_cache_delete( $cache_prefix . $fid . '_0', 'crocina_forms' );
			wp_cache_delete( $cache_prefix . $fid . '_1', 'crocina_forms' );
		} else {
			wp_cache_delete( $cache_prefix . 'total',    'crocina_forms' ); // old format
			wp_cache_delete( $cache_prefix . 'total_',  'crocina_forms' ); // new format, is_read=''
			wp_cache_delete( $cache_prefix . 'total_0', 'crocina_forms' );
			wp_cache_delete( $cache_prefix . 'total_1', 'crocina_forms' );
		}

		return $result ? (int) $result : 0;
	}

	/**
	 * @param int   $form_id
	 * @param int   $days
	 * @return array
	 */
	public function get_form_stats( $form_id = 0, $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'crocina_form_logs';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$where  = array();
		$params = array();

		if ( $form_id ) {
			$where[]  = 'form_id = %d';
			$params[] = absint( $form_id );
		}

		$now_local = time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		$cutoff_start = gmdate( 'Y-m-d H:i:s', $now_local - DAY_IN_SECONDS * max( 1, $days ) );
		$cutoff_end   = gmdate( 'Y-m-d H:i:s', $now_local + DAY_IN_SECONDS );
		$where[]  = 'submitted_at >= %s';
		$params[] = $cutoff_start;
		$where[]  = 'submitted_at < %s';
		$params[] = $cutoff_end;

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$limit = max( 1, absint( $days ) );

		$params[] = $limit;

		$daily_stats = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(submitted_at) as date, COUNT(*) as count
				 FROM {$table} {$where_sql}
				 GROUP BY DATE(submitted_at)
				 ORDER BY date DESC
				 LIMIT %d",
				$params
			)
		);

		$total = $this->get_logs_count( $form_id );

		return array(
			'total'        => $total,
			'recent_total' => array_sum( wp_list_pluck( $daily_stats, 'count' ) ),
			'daily_stats'  => $daily_stats,
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Cron-based FULLTEXT backfill                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Backfill payload_ft for rows that were inserted before the fallback
	 * column existed, or were created by external scripts that bypass the
	 * log() method (restores, imports, etc.).
	 *
	 * Processes a chunk of rows per run to avoid timeouts on large tables.
	 * Hooked to the 'crocina_forms_backfill_ft' cron action.
	 *
	 * @return void
	 */
	public function backfill_payload_ft() {
		global $wpdb;

		if ( 'column' !== $this->get_fallback_mode() ) {
			return;
		}

		$table = $wpdb->prefix . 'crocina_form_logs';
		$chunk = 200;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, payload FROM {$table} WHERE payload_ft = '' AND payload != '' LIMIT %d",
				$chunk
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$wpdb->update(
				$table,
				array( 'payload_ft' => $row->payload ),
				array( 'id' => $row->id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		// If there are still more rows to backfill, re-schedule for the next WP-Cron tick.
		$remaining = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE payload_ft = '' AND payload != ''"
		);

		if ( $remaining > 0 ) {
			wp_schedule_single_event( time() + 60, 'crocina_forms_backfill_ft' );
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Database status report                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Inspect the logs table and return a structured status report.
	 *
	 * The report includes:
	 *   - Whether the table exists
	 *   - The stored DB version
	 *   - A list of all current indexes and their columns
	 *   - Whether any legacy indexes (form_id, is_read) remain
	 *   - A FULLTEXT compatibility check (known to fail on some MariaDB versions)
	 *   - The database engine and version
	 *
	 * @return array Status data suitable for rendering in the admin or CLI.
	 */
	public function get_db_status() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'crocina_form_logs';
		$table_exists = self::table_exists( $table_name );

		$status = array(
			'table_name'    => $table_name,
			'table_exists'  => $table_exists,
			'db_version'    => get_option( 'crocina_logs_db_version', __( 'not set', 'crocina-forms' ) ),
			'db_engine'     => '',
			'db_server'     => '',
			'indexes'       => array(),
			'legacy_remain' => array(),
			'fulltext_ok'   => null,
			'fulltext_note' => '',
		);

		if ( ! $table_exists ) {
			return $status;
		}

		// Detect database server version.
		$server_info = $wpdb->get_var( 'SELECT VERSION()' );
		$status['db_server'] = $server_info ?: '';

		// Detect storage engine.
		$engine = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
				$table_name
			)
		);
		$status['db_engine'] = $engine ?: '';

		// List all current indexes.
		$index_rows = $wpdb->get_results(
			"SHOW INDEXES FROM {$table_name} WHERE Key_name != 'PRIMARY'"
		);

		$index_map = array();
		if ( ! empty( $index_rows ) ) {
			foreach ( $index_rows as $row ) {
				$name = $row->Key_name;
				if ( ! isset( $index_map[ $name ] ) ) {
					$index_map[ $name ] = array(
						'name'    => $name,
						'columns' => array(),
						'type'    => ( 'FULLTEXT' === strtoupper( $row->Index_type ) ) ? 'FULLTEXT' : ( $row->Non_unique ? 'INDEX' : 'UNIQUE' ),
					);
				}
				$index_map[ $name ]['columns'][] = $row->Column_name;
			}
		}
		$status['indexes'] = array_values( $index_map );

		// Detect legacy indexes that should have been dropped.
		$legacy_expected = array( 'form_id', 'is_read' );
		foreach ( $legacy_expected as $legacy ) {
			if ( isset( $index_map[ $legacy ] ) ) {
				$status['legacy_remain'][] = $legacy;
			}
		}

		// Check the fallback mode.
		$fallback_mode = get_option( self::FALLBACK_OPTION, '' );

		if ( 'column' === $fallback_mode ) {
			$status['fulltext_ok'] = true;
			$status['fulltext_note'] = __( 'FULLTEXT working via payload_ft fallback column (TEXT).', 'crocina-forms' );

			// Test the fallback FULLTEXT index.
			$ft_test = $wpdb->get_var(
				"SELECT COUNT(*) FROM {$table_name} WHERE MATCH(payload_ft) AGAINST('test' IN BOOLEAN MODE)"
			);
			if ( null === $ft_test && ! empty( $wpdb->last_error ) ) {
				$status['fulltext_ok'] = false;
				$status['fulltext_note'] = sprintf(
					__( 'Fallback FULLTEXT also failed: %s', 'crocina-forms' ),
					$wpdb->last_error
				);
			}
		} elseif ( 'like' === $fallback_mode ) {
			$status['fulltext_ok'] = false;
			$status['fulltext_note'] = __( 'FULLTEXT unavailable — search uses LIKE (slower but works everywhere).', 'crocina-forms' );
		} else {
			// No fallback; use the original payload column.
			$test_result = $wpdb->get_var(
				"SELECT COUNT(*) FROM {$table_name} WHERE MATCH(payload) AGAINST('test' IN BOOLEAN MODE)"
			);

			if ( null === $test_result && ! empty( $wpdb->last_error ) ) {
				$status['fulltext_ok'] = false;

				if ( false !== stripos( $server_info, 'MariaDB' ) ) {
					$status['fulltext_note'] = sprintf(
						/* translators: %s: database server version */
						__( 'FULLTEXT query failed on %s. MariaDB with InnoDB requires version 10.6+ for FULLTEXT indexes on LONGTEXT columns. Run the upgrade routine to create the fallback.', 'crocina-forms' ),
						$server_info
					);
				} else {
					$status['fulltext_note'] = sprintf(
						/* translators: %s: database error message */
						__( 'FULLTEXT query failed: %s', 'crocina-forms' ),
						$wpdb->last_error
					);
				}
			} else {
				$status['fulltext_ok'] = true;
				$status['fulltext_note'] = __( 'FULLTEXT index on payload is working correctly.', 'crocina-forms' );
			}
		}

		return $status;
	}

	/* ------------------------------------------------------------------ */
	/*  Migration log                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Append an entry to the migration log stored in the `crocina_migration_log` option.
	 *
	 * Each entry contains a human-readable description and the current GMT timestamp.
	 * The log is kept in reverse chronological order so the most recent step appears
	 * first, making it easy to verify the last operation when debugging upgrade issues
	 * on client sites.
	 *
	 * @param string $description  Brief description of the migration step.
	 * @return void
	 */
	public static function log_migration( $description ) {
		$log   = get_option( 'crocina_migration_log', array() );
		$entry = array(
			'timestamp'   => gmdate( 'Y-m-d H:i:s' ),
			'description' => (string) $description,
		);
		array_unshift( $log, $entry );

		// Keep at most 50 entries to avoid option bloat.
		if ( count( $log ) > 50 ) {
			$log = array_slice( $log, 0, 50 );
		}

		update_option( 'crocina_migration_log', $log, false );
	}

	/* ------------------------------------------------------------------ */
	/*  Migration: cleanup legacy indexes                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Full-text search fallback option name.
	 */
	private const FALLBACK_OPTION = 'crocina_fulltext_fallback';

	/**
	 * Drop single-column indexes that were replaced by compound indexes.
	 *
	 * The old schema (pre-1.4) had:
	 *   - KEY `form_id` (`form_id`)         → covered by `form_submitted (form_id, submitted_at)`
	 *   - KEY `is_read` (`is_read`)         → replaced by `form_read (form_id, is_read)`
	 *
	 * dbDelta does not drop unused indexes, so this method checks for their
	 * existence and issues ALTER TABLE … DROP INDEX for each.
	 *
	 * After cleanup, this method also checks whether the FULLTEXT index on
	 * `payload` exists. On MariaDB < 10.6 with InnoDB, FULLTEXT on LONGTEXT
	 * silently fails, so we create a fallback column + index or, as a last
	 * resort, store a flag that makes search queries use LIKE instead.
	 *
	 * @return void
	 */
	public function cleanup_legacy_indexes() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'crocina_form_logs';

		if ( ! self::table_exists( $table_name ) ) {
			return;
		}

		$legacy_indexes = array( 'form_id', 'is_read', 'submitted_at' );
		$existing_indexes = array();

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SHOW INDEXES FROM {$table_name} WHERE Key_name = %s OR Key_name = %s",
				$legacy_indexes[0],
				$legacy_indexes[1]
			)
		);

		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$existing_indexes[ $row->Key_name ] = true;
			}
		}

		foreach ( $legacy_indexes as $index_name ) {
			if ( ! empty( $existing_indexes[ $index_name ] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( "ALTER TABLE {$table_name} DROP INDEX `{$index_name}`" );

				self::log_migration( sprintf(
					'Dropped legacy index `%s` from %s.',
					$index_name,
					$table_name
				) );

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log(
						sprintf(
							'Crocina Logger: Dropped legacy index `%s` from %s.',
							$index_name,
							$table_name
						)
					);
				}
			}
		}

		// After legacy cleanup, ensure FULLTEXT is available for search.
		$this->ensure_fulltext_fallback();
	}

	/* ------------------------------------------------------------------ */
	/*  FULLTEXT fallback (MariaDB < 10.6 / InnoDB compatibility)          */
	/* ------------------------------------------------------------------ */

	/**
	 * Check whether the FULLTEXT index on `payload` exists and works.
	 *
	 * If it does not exist (MariaDB < 10.6 with InnoDB cannot FULLTEXT-index
	 * LONGTEXT columns), this method tries to add a companion TEXT column
	 * `payload_ft` with its own FULLTEXT index. If that also fails, it sets
	 * a fallback flag so search queries degrade gracefully to LIKE.
	 *
	 * The `payload_ft` column is kept in sync by the {@see log()} method at
	 * the application level, so no database triggers are needed.
	 *
	 * @return void
	 */
	private function ensure_fulltext_fallback() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'crocina_form_logs';

		// Check if the primary FULLTEXT index exists.
		$has_ft = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.STATISTICS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
				 AND INDEX_NAME = 'search_payload' AND INDEX_TYPE = 'FULLTEXT'",
				$table_name
			)
		);

		if ( $has_ft && (int) $has_ft > 0 ) {
			// FULLTEXT exists — clear any previous fallback flag.
			delete_option( self::FALLBACK_OPTION );
			$this->flush_fallback_cache();
			return;
		}

		/*
		 * FULLTEXT on payload (LONGTEXT) is missing — likely MariaDB < 10.6
		 * with InnoDB. Try to add a payload_ft TEXT column + FULLTEXT index.
		 * TEXT columns support FULLTEXT on InnoDB since MySQL 5.6 / MariaDB 10.0.
		 */
		$column_ok = $wpdb->get_results(
			"SHOW COLUMNS FROM {$table_name} WHERE Field = 'payload_ft'"
		);

		if ( empty( $column_ok ) ) {
			// Add the TEXT column for FULLTEXT indexing.
			$add_col = $wpdb->query(
				"ALTER TABLE {$table_name} ADD COLUMN payload_ft text NOT NULL AFTER payload"
			);

			if ( false === $add_col ) {
				// Cannot even add the column — set LIKE fallback.
				update_option( self::FALLBACK_OPTION, 'like', false );
				$this->flush_fallback_cache();
				self::log_migration( sprintf(
					'FULLTEXT unavailable and cannot add fallback column — search will use LIKE. Error: %s',
					$wpdb->last_error
				) );
				return;
			}

			// Backfill payload_ft with existing data.
			$wpdb->query( "UPDATE {$table_name} SET payload_ft = payload WHERE payload_ft = ''" );

			// Now add the FULLTEXT index on the TEXT column.
			$add_ft = $wpdb->query(
				"ALTER TABLE {$table_name} ADD FULLTEXT INDEX search_payload_ft (payload_ft)"
			);

			if ( false === $add_ft ) {
				// FULLTEXT still fails — clean up and fall back to LIKE.
				$wpdb->query( "ALTER TABLE {$table_name} DROP COLUMN payload_ft" );
				update_option( self::FALLBACK_OPTION, 'like', false );
				$this->flush_fallback_cache();
				self::log_migration( sprintf(
					'FULLTEXT fallback column added but index creation failed — search will use LIKE. Error: %s',
					$wpdb->last_error
				) );
				return;
			}

			update_option( self::FALLBACK_OPTION, 'column', false );
			$this->flush_fallback_cache();
			self::log_migration( sprintf(
				'FULLTEXT on payload (LONGTEXT) unavailable — created payload_ft (TEXT) column with FULLTEXT index and backfilled %d rows.',
				$wpdb->rows_affected ?: 0
			) );
		} else {
			// Column exists but index might be missing — try to add it.
			$has_ft_ft = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM information_schema.STATISTICS
					 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
					 AND INDEX_NAME = 'search_payload_ft' AND INDEX_TYPE = 'FULLTEXT'",
					$table_name
				)
			);

			if ( ! $has_ft_ft || (int) $has_ft_ft === 0 ) {
				$add_ft = $wpdb->query(
					"ALTER TABLE {$table_name} ADD FULLTEXT INDEX search_payload_ft (payload_ft)"
				);
				if ( false === $add_ft ) {
					update_option( self::FALLBACK_OPTION, 'like', false );
					$this->flush_fallback_cache();
					self::log_migration( 'FULLTEXT fallback column exists but index creation failed — search will use LIKE.' );
					return;
				}
			}

			update_option( self::FALLBACK_OPTION, 'column', false );
			$this->flush_fallback_cache();
		}
	}

	/** @var string|null Cached fallback mode for the current request. */
	private static $fallback_cache = null;

	/**
	 * Read the current fallback mode, caching it per request.
	 *
	 * @return string 'column', 'like', or ''.
	 */
	private function get_fallback_mode() {
		if ( null === self::$fallback_cache ) {
			self::$fallback_cache = get_option( self::FALLBACK_OPTION, '' );
		}
		return self::$fallback_cache;
	}

	/**
	 * Return the search WHERE clause and params for the payload column,
	 * automatically choosing FULLTEXT MATCH, fallback-column MATCH, or LIKE.
	 *
	 * @param string $search_term The raw search term from the user.
	 * @return array{where:string, params:array} Tuple of WHERE fragment and params.
	 */
	private function build_payload_search_clause( $search_term ) {
		global $wpdb;

		$fallback = $this->get_fallback_mode();
		$like     = '%' . $wpdb->esc_like( $search_term ) . '%';

		if ( 'like' === $fallback ) {
			// Worst case: no FULLTEXT available at all — use LIKE.
			return array(
				'where'  => '(payload LIKE %s OR page_url LIKE %s OR user_ip LIKE %s)',
				'params' => array( $like, $like, $like ),
			);
		}

		if ( 'column' === $fallback ) {
			// Fallback TEXT column with its own FULLTEXT index.
			return array(
				'where'  => '(MATCH(payload_ft) AGAINST(%s IN BOOLEAN MODE) OR page_url LIKE %s OR user_ip LIKE %s)',
				'params' => array( $search_term, $like, $like ),
			);
		}

		// Default: FULLTEXT on the original payload column.
		return array(
			'where'  => '(MATCH(payload) AGAINST(%s IN BOOLEAN MODE) OR page_url LIKE %s OR user_ip LIKE %s)',
			'params' => array( $search_term, $like, $like ),
		);
	}

	/**
	 * Sync the payload_ft column with the current payload value.
	 * Called from {@see log()} when the fallback column exists.
	 *
	 * @param int $log_id The log row ID.
	 * @param string $payload_json The JSON-encoded payload string.
	 * @return void
	 */
	private function sync_payload_ft( $log_id, $payload_json ) {
		global $wpdb;

		if ( 'column' !== $this->get_fallback_mode() ) {
			return;
		}

		$table = $wpdb->prefix . 'crocina_form_logs';
		$wpdb->update(
			$table,
			array( 'payload_ft' => $payload_json ),
			array( 'id' => $log_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Invalidate the per-request fallback cache.
	 * Called from {@see ensure_fulltext_fallback()} after changing the option.
	 *
	 * @return void
	 */
	private function flush_fallback_cache() {
		self::$fallback_cache = null;
	}

	/* ------------------------------------------------------------------ */
	/*  Private helpers                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array $payload
	 * @return string
	 */
	private function prepare_payload_json( $payload ) {
		$payload_json = wp_json_encode( $payload );
		if ( false === $payload_json ) {
			return wp_json_encode( array( 'error' => 'payload_encode_failed' ) );
		}

		if ( strlen( $payload_json ) > 200000 ) {
			$payload      = $this->truncate_payload( $payload );
			$payload_json = wp_json_encode( $payload );

			if ( is_string( $payload_json ) && strlen( $payload_json ) > 200000 ) {
				$payload_json = wp_json_encode( array(
					'form_id'      => $payload['form_id'] ?? 0,
					'submitted_at' => $payload['submitted_at'] ?? '',
					'error'        => 'payload_truncated',
					'note'         => 'Original payload exceeded the storage limit and was truncated.',
				) );
			}
		}

		return $payload_json;
	}

	/**
	 * @param array $payload
	 * @return array
	 */
	private function truncate_payload( $payload ) {
		if ( isset( $payload['fields'] ) && is_array( $payload['fields'] ) ) {
			if ( count( $payload['fields'] ) > 100 ) {
				$payload['fields'] = array_slice( $payload['fields'], 0, 100 );
			}
			foreach ( $payload['fields'] as &$field ) {
				if ( isset( $field['value'] ) && is_string( $field['value'] ) ) {
					$field['value'] = substr( $field['value'], 0, 1000 );
				}
			}
			unset( $field );
		}

		foreach ( $payload as $key => &$member ) {
			if ( 'fields' === $key ) {
				continue;
			}
			if ( is_string( $member ) && strlen( $member ) > 2000 ) {
				$member = substr( $member, 0, 2000 );
			}
		}
		unset( $member );

		return $payload;
	}

	/**
	 * @param string $table
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		if ( isset( self::$table_exists_cache[ $table ] ) ) {
			return self::$table_exists_cache[ $table ];
		}

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		self::$table_exists_cache[ $table ] = $exists;

		return $exists;
	}

	/**
	 * @param string $table
	 * @param bool   $exists
	 * @return void
	 */
	private static function set_table_exists_cache( $table, $exists ) {
		self::$table_exists_cache[ $table ] = (bool) $exists;
	}
}
