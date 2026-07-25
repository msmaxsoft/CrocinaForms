<?php
/**
 * Crocina Form List Table — displays crocina_form posts in the dashboard.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Crocina_Form_List_Table extends WP_List_Table {

	/** @var string Search query (empty = no filter). */
	private $search = '';

	/** @var Crocina_Logger|null */
	private $logger;

	/** @var int Total items found (for pagination). */
	private $total_items = 0;

	public function __construct( $args = array() ) {
		parent::__construct( array(
			'singular' => 'crocina_form',
			'plural'   => 'crocina_forms',
			'ajax'     => false,
			'screen'   => $args['screen'] ?? null,
		) );
		$this->search = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
		$this->logger = isset( $args['logger'] ) ? $args['logger'] : null;
	}

	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'title'      => __( 'Title', 'crocina-forms' ),
			'shortcode'  => __( 'Shortcode', 'crocina-forms' ),
			'updated'    => __( 'Last Updated', 'crocina-forms' ),
			'submissions'=> __( 'Submissions', 'crocina-forms' ),
		);
	}

	public function get_sortable_columns() {
		return array(
			'title'  => array( 'title', false ),
			'updated' => array( 'modified', true ),
		);
	}

	public function get_bulk_actions() {
		return array(
			'duplicate' => __( 'Duplicate', 'crocina-forms' ),
			'delete'    => __( 'Delete', 'crocina-forms' ),
		);
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="form_ids[]" value="%d" />', absint( $item->ID ) );
	}

	public function column_title( $item ) {
		$edit_link = get_edit_post_link( $item->ID );
		$title     = esc_html( get_the_title( $item ) );

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html__( 'Edit', 'crocina-forms' ) ),
			'trash'  => sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( get_delete_post_link( $item->ID ) ), esc_html__( 'Trash', 'crocina-forms' ) ),
			'view'   => sprintf( '<a href="%s" target="_blank" rel="noreferrer noopener">%s</a>', esc_url( get_permalink( $item ) ), esc_html__( 'View', 'crocina-forms' ) ),
		);

		return sprintf(
			'<strong><a href="%1$s" class="row-title">%2$s</a></strong>%3$s',
			esc_url( $edit_link ),
			$title,
			$this->row_actions( $actions )
		);
	}

	public function column_shortcode( $item ) {
		return sprintf( '<code>[crocina_form id="%d"]</code>', absint( $item->ID ) );
	}

	public function column_updated( $item ) {
		return esc_html( get_the_modified_date( 'Y-m-d H:i', $item ) );
	}

	public function column_submissions( $item ) {
		if ( $this->logger ) {
			$count = $this->logger->get_logs_count( $item->ID );
			return esc_html( number_format_i18n( $count ) );
		}
		return '—';
	}

	public function column_default( $item, $column_name ) {
		return '';
	}

	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'crocina_forms_per_page', 20 );
		$current_page = $this->get_pagenum();

		$args = array(
			'post_type'      => 'crocina_form',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => $per_page,
			'paged'          => $current_page,
		);

		if ( $this->search ) {
			$args['s'] = $this->search;
		}

		$query = new WP_Query( $args );
		$this->items = $query->posts;
		$this->total_items = (int) $query->found_posts;

		$this->set_pagination_args( array(
			'total_items' => $this->total_items,
			'per_page'    => $per_page,
		) );

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);
	}
}
