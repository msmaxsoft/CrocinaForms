<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Crocina_Inbox_List_Table extends WP_List_Table {

	use Crocina_Input_Helper;
    /** @var int|null Filtered form ID. */
    private $form_id;
    /** @var string The admin screen ID. */
    private $screen_id = 'crocina-forms-inbox';
    /** @var string */
    private $search_term = '';
    /** @var string Filter by read status: '' = all, '0' = unread, '1' = read */
    private $is_read = '';

    /** @var Crocina_Logger_Interface|null */
    private $logger;

    /** @var Crocina_Forms_Core|null */
    private $core;

    /** @var Crocina_Notifications|null */
    private $notifications;

    public function __construct( $args = array() ) {
        parent::__construct( array(
            'singular' => 'crocina_log',
            'plural'   => 'crocina_logs',
            'ajax'     => false,
        ) );
        $this->form_id = isset( $args['form_id'] ) ? absint( $args['form_id'] ) : 0;
        $this->search_term = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
        $this->is_read     = isset( $args['is_read'] ) && in_array( (string) $args['is_read'], array( '0', '1' ), true ) ? (string) $args['is_read'] : '';
        $this->logger = isset( $args['logger'] ) ? $args['logger'] : null;
        $this->core = isset( $args['core'] ) ? $args['core'] : null;
        $this->notifications = isset( $args['notifications'] ) ? $args['notifications'] : null;
    }

    public function get_columns() {
        return array(
            'cb'     => '<input type="checkbox" />',
            'date'   => __( 'Date', 'crocina-forms' ),
            'jalali' => __( 'Date (Jalali)', 'crocina-forms' ),
            'form'   => __( 'Form', 'crocina-forms' ),
            'page'   => __( 'Page', 'crocina-forms' ),
            'ip'     => __( 'IP', 'crocina-forms' ),
            'fields' => __( 'Fields', 'crocina-forms' ),
        );
    }

    public function get_sortable_columns() {
        return array(
            'date' => array( 'submitted_at', true ),
            'form' => array( 'form_id', false ),
            'ip'   => array( 'user_ip', false ),
        );
    }

    public function get_bulk_actions() {
        $actions = array(
            'delete' => __( 'Delete', 'crocina-forms' ),
        );

        // Only show 'Mark read' if there are unread items visible.
        $actions['mark_read'] = __( 'Mark as read', 'crocina-forms' );

        return $actions;
    }

    public function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="log[]" value="%d" />', absint( $item->id ) );
    }

    public function build_fields_list( $log ) {
        $payload = json_decode( $log->payload, true, 512 );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
            return '<em>' . esc_html__( 'No data', 'crocina-forms' ) . '</em>';
        }

        $fields = array();
        if ( isset( $payload['fields'] ) && is_array( $payload['fields'] ) ) {
            $fields = $payload['fields'];
        } else {
            $fields = $payload;
        }

        if ( empty( $fields ) ) {
            return '<em>' . esc_html__( 'No data', 'crocina-forms' ) . '</em>';
        }

        $items = array();
        foreach ( $fields as $field ) {
            $label = '';
            $value = '';

            if ( is_array( $field ) && isset( $field['label'], $field['value'] ) ) {
                $label = $field['label'];
                $value = $field['value'];
            } elseif ( is_array( $field ) ) {
                $label = (string) key( $field );
                $value = current( $field );
            } else {
                $value = $field;
            }

            if ( is_array( $value ) ) {
                $value = implode( ', ', array_map( 'sanitize_text_field', $value ) );
            } else {
                $value = sanitize_text_field( (string) $value );
            }

            $value = self::truncate_string( $value, 100 );

            if ( '' !== $label ) {
                $items[] = sprintf(
                    '<div class="crocina-inbox-field-row"><span class="crocina-inbox-field-label">%s:</span> <span class="crocina-inbox-field-value">%s</span></div>',
                    esc_html( $label ),
                    esc_html( $value )
                );
            } else {
                $items[] = sprintf(
                    '<div class="crocina-inbox-field-row"><span class="crocina-inbox-field-value">%s</span></div>',
                    esc_html( $value )
                );
            }
        }

        return '<div class="crocina-inbox-fields">' . implode( '', $items ) . '</div>';
    }

    public function column_form( $item ) {
        $title = get_the_title( $item->form_id );
        if ( $title && function_exists( 'mb_substr' ) ) {
            $initial = mb_substr( $title, 0, 1, 'UTF-8' );
        } else {
            $initial = $title ? substr( $title, 0, 1 ) : '#';
        }

        return sprintf(
            '<div class="crocina-inbox-form-cell"><span class="crocina-inbox-avatar">%1$s</span><span>%2$s</span></div>',
            esc_html( $initial ),
            esc_html( $title )
        );
    }

    public function column_date( $item ) {
        $detail_url = add_query_arg(
            array(
                'page'   => 'crocina-forms-inbox',
                'view'   => 'detail',
                'log_id' => $item->id,
            ),
            admin_url( 'admin.php' )
        );
        $actions = array(
            'quick_view' => sprintf(
                '<a href="#" class="crocina-quick-view" data-log-id="%1$d">%2$s</a>',
                absint( $item->id ),
                esc_html__( 'Quick view', 'crocina-forms' )
            ),
            'delete' => sprintf(
                '<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
                wp_nonce_url(
                    add_query_arg(
                        array(
                            'action' => 'crocina_delete_log',
                            'log_id' => $item->id,
                            'page'   => 'crocina-forms-inbox',
                        ),
                        admin_url( 'admin-post.php' )
                    ),
                    'crocina_delete_log',
                    'crocina_delete_nonce'
                ),
                esc_js( __( 'Are you sure you want to delete this log?', 'crocina-forms' ) ),
                esc_html__( 'Delete', 'crocina-forms' )
            ),
        );

        if ( empty( $item->is_read ) ) {
            $actions['mark_read'] = sprintf(
                '<a href="#" class="crocina-mark-read" data-log-id="%1$d">%2$s</a>',
                absint( $item->id ),
                esc_html__( 'Mark as read', 'crocina-forms' )
            );
        }

        $date_link = sprintf(
            '<a href="%s" title="%s">%s</a>',
            esc_url( $detail_url ),
            esc_attr__( 'View details', 'crocina-forms' ),
            esc_html( $item->submitted_at )
        );

        $badge = sprintf(
            '<span class="crocina-inbox-badge %1$s" data-read-label="%3$s" data-unread-label="%4$s">%2$s</span>',
            empty( $item->is_read ) ? 'is-unread' : 'is-read',
            empty( $item->is_read ) ? esc_html__( 'New', 'crocina-forms' ) : esc_html__( 'Read', 'crocina-forms' ),
            esc_html__( 'Read', 'crocina-forms' ),
            esc_html__( 'New', 'crocina-forms' )
        );

        return $badge . ' ' . $date_link . $this->row_actions( $actions );
    }

    public function column_page( $item ) {
        if ( empty( $item->page_url ) ) {
            return '<em>' . esc_html__( 'n/a', 'crocina-forms' ) . '</em>';
        }

        $url = esc_url( $item->page_url );
        $display = wp_parse_url( $item->page_url, PHP_URL_PATH );
        if ( ! $display ) {
            $display = $item->page_url;
        }

        if ( is_string( $display ) ) {
            $display = self::truncate_string( $display, 50 );
        }

        return sprintf( '<a href="%1$s" target="_blank" rel="noreferrer noopener">%2$s</a>', $url, esc_html( $display ) );
    }

    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'jalali':
                if ( $this->core ) {
                    $settings = $this->core->get_global_settings();
                    $format   = $settings['jalali_date_format'] ?? 'short';
                    return esc_html( $this->core->format_jalali_display( strtotime( $item->submitted_at ), $format ) );
                }
                return esc_html( $item->submitted_at ?? '' );
            case 'form':
                return esc_html( get_the_title( $item->form_id ) );
            case 'ip':
                return esc_html( $item->user_ip );
            case 'fields':
                return $this->build_fields_list( $item );
            default:
                return '';
        }
    }

    public function prepare_items() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $this->process_bulk_action();

        $per_page     = max( 1, (int) $this->get_items_per_page( 'crocina_inbox_per_page', 20 ) );
        $current_page = $this->get_pagenum();
        $allowed_orderby = array( 'submitted_at', 'form_id', 'user_ip', 'id' );
        $requested_orderby = $this->has_get( 'orderby' ) ? sanitize_key( wp_unslash( $this->input_get( 'orderby' ) ) ) : 'submitted_at';
        $orderby = in_array( $requested_orderby, $allowed_orderby, true ) ? $requested_orderby : 'submitted_at';
        $requested_order = $this->has_get( 'order' ) ? strtoupper( sanitize_text_field( wp_unslash( $this->input_get( 'order' ) ) ) ) : 'DESC';
        $order = in_array( $requested_order, array( 'ASC', 'DESC' ), true ) ? $requested_order : 'DESC';

        if ( ! $this->logger ) {
            $this->items = array();
            $this->set_pagination_args( array(
                'total_items' => 0,
                'per_page'    => $per_page,
            ) );
            return;
        }

        if ( $this->search_term ) {
            $logs = $this->logger->search_logs( $this->search_term, array(
                'form_id'  => $this->form_id,
                'per_page' => $per_page,
                'page'     => $current_page,
                'order'    => $order,
                'orderby'  => $orderby,
                'is_read'  => $this->is_read,
            ) );
            $total_items = $this->logger->search_logs_count( $this->search_term, $this->form_id, $this->is_read );
        } else {
            $logs = $this->logger->get_logs( array(
                'form_id'  => $this->form_id,
                'per_page' => $per_page,
                'page'     => $current_page,
                'order'    => $order,
                'orderby'  => $orderby,
                'is_read'  => $this->is_read,
            ) );
            $total_items = $this->logger->get_logs_count( $this->form_id, $this->is_read );
        }

        $hidden_columns = $this->resolve_hidden_columns();

        $this->_column_headers = array( $this->get_columns(), $hidden_columns, $this->get_sortable_columns() );
        $this->items           = $logs;

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
        ) );
    }

    public function single_row( $item ) {
        $classes = empty( $item->is_read ) ? 'is-unread' : 'is-read';
        echo '<tr class="' . esc_attr( $classes ) . '">';
        $this->single_row_columns( $item );
        echo '</tr>';
    }

    private function resolve_hidden_columns() {
        $screen = get_current_screen();
        if ( $screen && function_exists( 'get_hidden_columns' ) ) {
            $hidden = get_hidden_columns( $screen );
            return is_array( $hidden ) ? $hidden : array();
        }

        return array();
    }

    public function process_bulk_action() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'crocina-forms' ) );
        }

        $action = $this->current_action();
        if ( ! $action ) {
            return;
        }

        check_admin_referer( 'bulk-' . $this->_args['plural'] );

        $log_ids = $this->has_post( 'log' ) ? array_map( 'absint', (array) $this->input_post( 'log' ) ) : array();
        if ( ! $log_ids ) {
            return;
        }

        if ( 'delete' === $action && $this->logger ) {
            foreach ( $log_ids as $log_id ) {
                $this->logger->delete_log( $log_id );
            }

            $redirect = remove_query_arg( array( 'crocina_bulk', 'crocina_bulk_count' ) );
            $redirect = add_query_arg(
                array(
                    'crocina_bulk'       => 'deleted',
                    'crocina_bulk_count' => count( $log_ids ),
                ),
                $redirect
            );
            wp_safe_redirect( $redirect );
            exit;
        }

        if ( 'mark_read' === $action && $this->logger ) {
            foreach ( $log_ids as $log_id ) {
                $this->logger->mark_read( $log_id );
            }

            $redirect = remove_query_arg( array( 'crocina_bulk', 'crocina_bulk_count' ) );
            $redirect = add_query_arg(
                array(
                    'crocina_bulk'       => 'mark_read',
                    'crocina_bulk_count' => count( $log_ids ),
                ),
                $redirect
            );
            wp_safe_redirect( $redirect );
            exit;
        }

        if ( 'resend' === $action && class_exists( 'Crocina_Notifications' ) && $this->logger ) {
            foreach ( $log_ids as $log_id ) {
                $log = $this->logger->get_log( $log_id );
                if ( ! $log ) {
                    continue;
                }

                $payload = json_decode( $log->payload, true );
                if ( ! is_array( $payload ) ) {
                    $payload = array();
                }
                $payload['form_id'] = $log->form_id;
                $payload['submitted_at'] = $payload['submitted_at'] ?? $log->submitted_at;
                $payload['user_ip'] = $payload['user_ip'] ?? $log->user_ip;
                $payload['page_url'] = $payload['page_url'] ?? $log->page_url;

                if ( $this->notifications ) {
                    $this->notifications->dispatch( $log->form_id, $payload );
                }
            }

            $redirect = remove_query_arg( array( 'crocina_bulk', 'crocina_bulk_count' ) );
            $redirect = add_query_arg(
                array(
                    'crocina_bulk'       => 'resent',
                    'crocina_bulk_count' => count( $log_ids ),
                ),
                $redirect
            );
            wp_safe_redirect( $redirect );
            exit;
        }
    }

    private static function truncate_string( $string, $length ) {
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $string, 'UTF-8' ) <= $length ) {
                return $string;
            }
            return mb_substr( $string, 0, $length - 3, 'UTF-8' ) . '...';
        }

        if ( strlen( $string ) <= $length ) {
            return $string;
        }

        return substr( $string, 0, $length - 3 ) . '...';
    }
}

