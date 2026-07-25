<?php
/**
 * PHPStan stubs — declare WordPress core classes that the plugin references
 * but are not fully defined in the wordpress-stubs package (e.g. WP_List_Table,
 * WP_Posts_List_Table, etc.).
 *
 * @package Crocina_Forms
 */

// WP_List_Table is conditionally loaded in wp-admin; PHPStan won't find it.
if ( ! class_exists( 'WP_List_Table' ) ) {
	/**
	 * @property array $_column_headers
	 */
	class WP_List_Table {
		/**
		 * @param array<string,string> $args
		 */
		public function __construct( $args = array() ) {
		}
		/**
		 * @return void
		 */
		public function prepare_items() {
		}
		/**
		 * @return void
		 */
		public function display() {
		}
		/**
		 * @param object $item
		 * @param string $column_name
		 * @return string
		 */
	 protected function column_default( $item, $column_name ) {
				return '';
		}
		/**
		 * @return array<int,array<string,string>>
		 */
		protected function get_bulk_actions() {
			return array();
		}
		/**
		 * @param string $which
		 * @return void
		 */
		protected function bulk_actions( $which = '' ) {
		}
		/**
		 * @param object $item
		 * @return string
		 */
		protected function row_actions( $actions, $always_visible = false ) {
			return '';
		}
		/**
		 * @param object $item
		 * @return void
		 */
		public function single_row( $item ) {
		}
	}
}

// WP_Posts_List_Table extends WP_List_Table.
if ( ! class_exists( 'WP_Posts_List_Table' ) ) {
	/**
	 * @property array $items
	 */
	class WP_Posts_List_Table extends WP_List_Table {
	}
}
