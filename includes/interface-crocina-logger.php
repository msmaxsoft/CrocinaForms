<?php
/**
 * Logger Interface — contract for log persistence.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

interface Crocina_Logger_Interface {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init();

	/**
	 * Create or update the logs database table.
	 *
	 * @return void
	 */
	public function install_table();

	/**
	 * Upgrade the database schema if the version is outdated.
	 *
	 * @return void
	 */
	public function maybe_upgrade();

	/**
	 * Record a form submission.
	 *
	 * @param int   $form_id
	 * @param array $payload
	 * @return int Inserted log ID, or 0 on failure.
	 */
	public function log( $form_id, $payload );

	/**
	 * Retrieve a single log entry.
	 *
	 * @param int $log_id
	 * @return object|null
	 */
	public function get_log( $log_id );

	/**
	 * Prune logs older than the specified number of days.
	 *
	 * @param int $days
	 * @return void
	 */
	public function prune( $days );

	/**
	 * Delete all logs for a given form.
	 *
	 * @param int $form_id
	 * @return int Number of deleted rows.
	 */
	public function prune_form( $form_id );

	/**
	 * Retrieve paginated log entries.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type int    $form_id  Filter by form ID.
	 *     @type int    $per_page Results per page.
	 *     @type int    $page     Page number.
	 *     @type string $orderby  Column to sort by.
	 *     @type string $order    ASC or DESC.
	 * }
	 * @return array
	 */
	public function get_logs( $args = array() );

	/**
	 * Delete a single log entry.
	 *
	 * @param int $log_id
	 * @return int 1 on success, 0 on failure.
	 */
	public function delete_log( $log_id );

	/**
	 * Search logs by keyword.
	 *
	 * @param string $search
	 * @param array  $args    Optional query arguments (same shape as get_logs).
	 * @return array
	 */
	public function search_logs( $search, $args = array() );

	/**
	 * Count search results.
	 *
	 * @param string $search
	 * @param int    $form_id Optional form ID filter.
	 * @return int
	 */
	public function search_logs_count( $search, $form_id = 0 );

	/**
	 * Count log entries, optionally filtered by form.
	 *
	 * @param int $form_id Optional form ID.
	 * @return int
	 */
	public function get_logs_count( $form_id = 0 );

	/**
	 * Count unread entries, optionally filtered by form.
	 *
	 * @param int $form_id Optional form ID.
	 * @return int
	 */
	public function get_unread_count( $form_id = 0 );

	/**
	 * Mark a log as read.
	 *
	 * @param int $log_id
	 * @return bool
	 */
	public function mark_read( $log_id );

	/**
	 * Mark a log as unread.
	 *
	 * @param int $log_id
	 * @return bool
	 */
	public function mark_unread( $log_id );

	/**
	 * Get submission statistics for charts / widgets.
	 *
	 * @param int $form_id Optional form ID.
	 * @param int $days    Number of days.
	 * @return array {
	 *     @type int   $total        Total submissions ever.
	 *     @type int   $recent_total Submissions in the period.
	 *     @type array $daily_stats  Array of daily-count objects.
	 * }
	 */
	public function get_form_stats( $form_id = 0, $days = 30 );
}
