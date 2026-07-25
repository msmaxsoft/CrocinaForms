<?php
/**
 * Admin Page Renderer — renders the dashboard, settings, inbox, and export pages.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

final class Crocina_Admin_Page_Renderer {

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
	 * Register hooks (if any page-specific hooks are needed).
	 */
	public function init() {
		// No standalone hooks needed — pages are called from Crocina_Admin menus.
	}

	/**
	 * Render the dashboard page.
	 */
	public function render_dashboard_page() {
		$search_query = '';
		$admin = $this->app->resolve( 'admin' );
		if ( ! empty( $_GET['crocina_form_search'] ) && $admin->verify_get_nonce( 'crocina_dashboard_search_nonce', 'crocina_dashboard_search' ) ) {
			$search_query = sanitize_text_field( wp_unslash( $_GET['crocina_form_search'] ) );
		}

		$total_forms = wp_count_posts( 'crocina_form' )->publish ?? 0;
		$total_logs  = $this->app->resolve( 'logger' )->get_logs_count();

		$core = $this->app->resolve( 'core' );
		$settings = $core->get_global_settings();
		$stats_days = max( 1, absint( $settings['dashboard_stats_days'] ?? 7 ) );
		$logger = $this->app->resolve( 'logger' );
		$daily_stats = $logger ? $logger->get_form_stats( 0, $stats_days ) : array();
		if ( is_array( $daily_stats ) && isset( $daily_stats['daily_stats'] ) ) {
			$daily_stats = $daily_stats['daily_stats'];
		} else {
			$daily_stats = array();
		}

		// Create WP_List_Table for the dashboard form listing.
		$table = new Crocina_Form_List_Table( array(
			'search' => $search_query,
			'logger' => $logger,
		) );

		$render = $this->app->resolve( 'render' );
		echo $render->render(
			'admin/dashboard',
			array(
				'search_query' => $search_query,
				'total_forms'  => $total_forms,
				'total_logs'   => $total_logs,
				'table'        => $table,
				'daily_stats'  => $daily_stats,
				'stats_days'   => $stats_days,
			)
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		$core     = $this->app->resolve( 'core' );
		$admin    = $this->app->resolve( 'admin' );
		$render   = $this->app->resolve( 'render' );
		$settings = $core->get_global_settings();
		$admin->enqueue_admin_style_once();
		$sample_ts    = time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );

		// Database status report.
		$logger = $this->app->resolve( 'logger' );
		$db_status = $logger ? $logger->get_db_status() : null;

		// Preview vars removed — the notification preview section was deleted from the template.

		// Gather cache performance counters for the System Status tab.
		$cache_stats = $core->get_cache_stats();

		echo $render->render(
			'admin/settings-modern',
			array(
				'settings'              => $settings,
				// preview_base, preview_gregorian, preview_jalali — removed with notification preview.
				'db_status'             => $db_status,
				'cache_stats'           => $cache_stats,
			)
		);
	}

	/**
	 * Render the inbox page.
	 */
	public function render_inbox_page() {
		$admin  = $this->app->resolve( 'admin' );
		$core   = $this->app->resolve( 'core' );
		$logger = $this->app->resolve( 'logger' );
		$render = $this->app->resolve( 'render' );

		$log_search = '';
		if ( ! empty( $_GET['s'] ) && $admin->verify_get_nonce( 'crocina_inbox_log_search_nonce', 'crocina_inbox_log_search' ) ) {
			$log_search = sanitize_text_field( wp_unslash( $_GET['s'] ) );
		}
		$form_query_args = array(
			'post_type'   => 'crocina_form',
			'post_status' => array( 'publish', 'draft' ),
			'numberposts' => -1,
		);
		$forms = get_posts( $form_query_args );
		$filter_form_id = 0;
		if ( ! empty( $_GET['form_id'] ) && $admin->verify_get_nonce( 'crocina_inbox_filter_nonce', 'crocina_inbox_filter' ) ) {
			$filter_form_id = absint( $_GET['form_id'] );
		}

		// Read status filter: '' = all, '0' = unread, '1' = read.
		$is_read = isset( $_GET['is_read'] ) ? sanitize_key( $_GET['is_read'] ) : '';
		if ( ! in_array( $is_read, array( '0', '1' ), true ) ) {
			$is_read = '';
		}

		$admin->enqueue_admin_style_once();
		$table = $admin->ensure_inbox_table( $filter_form_id, $log_search, $is_read );
		if ( ! $table ) {
			wp_die( esc_html__( 'Unable to display inbox.', 'crocina-forms' ), '', array( 'response' => 500 ) );
		}
		$table->prepare_items();

		$detail_log = null;
		$view = sanitize_key( $_GET['view'] ?? '' );
		if ( 'detail' === $view && ! empty( $_GET['log_id'] ) && $logger ) {
			$detail_log = $logger->get_log( absint( $_GET['log_id'] ) );
		}
		$detail_payload = array();
		if ( $detail_log ) {
			$detail_payload = json_decode( $detail_log->payload, true );
			if ( ! is_array( $detail_payload ) ) {
				$detail_payload = array();
			}
		}

		$resend_status = sanitize_key( $_GET['crocina_resend'] ?? '' );
		$delete_status = sanitize_key( $_GET['crocina_delete'] ?? '' );
		$bulk_status   = sanitize_key( $_GET['crocina_bulk'] ?? '' );
		$bulk_count    = absint( $_GET['crocina_bulk_count'] ?? 0 );
		$total_unread  = $logger ? $logger->get_unread_count( $filter_form_id ) : 0;

		echo $render->render(
			'admin/inbox-modern',
			array(
				'forms'          => $forms,
				'filter_form_id' => $filter_form_id,
				'log_search'     => $log_search,
				'is_read'        => $is_read,
				'table'          => $table,
				'detail_log'     => $detail_log,
				'detail_payload' => $detail_payload,
				'resend_status'  => $resend_status,
				'delete_status'  => $delete_status,
				'bulk_status'    => $bulk_status,
				'bulk_count'     => $bulk_count,
				'total_unread'   => $total_unread,
				'mark_read_nonce'=> wp_create_nonce( 'crocina_mark_read' ),
				'quick_view_nonce' => wp_create_nonce( 'crocina_quick_view_log' ),
			)
		);
	}

	/**
	 * Render the Event Log viewer page.
	 *
	 * Reads the last 100 lines from the events.log file (inside the
	 * WordPress uploads directory) and displays them with basic line
	 * formatting. Includes a Clear button to truncate the log.
	 */
	public function render_event_log_page() {
		$admin = $this->app->resolve( 'admin' );
		$admin->enqueue_admin_style_once();

		$uploads  = wp_get_upload_dir();
		$log_file = ( $uploads['basedir'] ?? '' ) . '/crocina-forms/events.log';
		$log_size = 0;
		$lines    = array();
		$exists   = is_file( $log_file );

		if ( $exists ) {
			$log_size = filesize( $log_file );
			// Read the entire file and keep only the last 100 lines.
			$content = file_get_contents( $log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $content && '' !== $content ) {
				$all_lines = explode( "\n", $content );
				$lines     = array_slice( $all_lines, -100 );
			}
		}

		$render = $this->app->resolve( 'render' );
		echo $render->render(
			'admin/event-log',
			array(
				'exists'    => $exists,
				'log_size'  => $log_size,
				'lines'     => $lines,
				'clear_nonce' => wp_create_nonce( 'crocina_clear_event_log' ),
			)
		);
	}

	/**
	 * Render the export & import page.
	 */
	public function render_export_page() {
		$admin  = $this->app->resolve( 'admin' );
		$core   = $this->app->resolve( 'core' );
		$logger = $this->app->resolve( 'logger' );
		$render = $this->app->resolve( 'render' );

		$data = $admin->gather_export_data();
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		$meta = $data['meta'] ?? array();
		$exported_at = $meta['exported_at'] ?? '';
		$exported_at_label = $exported_at ? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $exported_at ) : '';
		$format_version = $meta['format_version'] ?? '1.0';
		$forms_exported = $meta['forms_exported'] ?? 0;
		$logs_exported = $meta['logs_exported'] ?? 0;
		$recent_log = $meta['preview']['recent_log'] ?? null;
		$settings   = $core->get_global_settings();
		$stats_days = max( 1, absint( $settings['dashboard_stats_days'] ?? 30 ) );
		$stats      = $logger ? $logger->get_form_stats( 0, $stats_days ) : array();
		$admin->enqueue_admin_style_once();
		echo $render->render(
			'admin/export-modern',
			array(
				'json'              => $json,
				'exported_at_label' => $exported_at_label,
				'format_version'    => $format_version,
				'forms_exported'    => $forms_exported,
				'logs_exported'     => $logs_exported,
				'recent_log'        => $recent_log,
				'stats'             => $stats,
				'stats_days'        => $stats_days,
				'settings'          => $settings,
			)
		);
	}

}
