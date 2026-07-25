<?php
defined( 'ABSPATH' ) || exit;

$data = is_array( $data ?? null ) ? $data : array();
$forms = $data['forms'] ?? array();
$filter_form_id = absint( $data['filter_form_id'] ?? 0 );
$log_search = $data['log_search'] ?? '';
$is_read_filter = $data['is_read'] ?? '';
$table = $data['table'] ?? null;
$resend_status = $data['resend_status'] ?? '';
$delete_status = $data['delete_status'] ?? '';
$bulk_status = $data['bulk_status'] ?? '';
$bulk_count = absint( $data['bulk_count'] ?? 0 );
$total_unread = absint( $data['total_unread'] ?? 0 );
$mark_read_nonce = $data['mark_read_nonce'] ?? '';
$quick_view_nonce = $data['quick_view_nonce'] ?? '';
?>
<div class="wrap" data-mark-read-nonce="<?php echo esc_attr( $mark_read_nonce ); ?>" data-quick-view-nonce="<?php echo esc_attr( $quick_view_nonce ); ?>">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Inbox', 'crocina-forms' ); ?></h1>
	<hr class="wp-header-end" />

	<?php if ( 'success' === $resend_status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Notifications resent successfully.', 'crocina-forms' ); ?></p></div>
	<?php elseif ( 'missing' === $resend_status ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Unable to find the requested log entry.', 'crocina-forms' ); ?></p></div>
	<?php endif; ?>

	<?php if ( 'success' === $delete_status ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Log deleted successfully.', 'crocina-forms' ); ?></p></div>
	<?php elseif ( 'failed' === $delete_status ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Failed to delete the selected log.', 'crocina-forms' ); ?></p></div>
	<?php endif; ?>

	<?php if ( $bulk_status && $bulk_count ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php
				if ( 'deleted' === $bulk_status ) {
					printf( esc_html( _n( '%d log deleted.', '%d logs deleted.', $bulk_count, 'crocina-forms' ) ), $bulk_count );
				} elseif ( 'resent' === $bulk_status ) {
					printf( esc_html( _n( '%d log resent.', '%d logs resent.', $bulk_count, 'crocina-forms' ) ), $bulk_count );
				}
			?></p>
		</div>
	<?php endif; ?>

	<div id="poststuff" style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start;">
		<aside style="flex:0 0 280px;max-width:100%;">
			<div class="postbox">
				<div class="postbox-header"><h2 class="hndle"><span><?php esc_html_e( 'New messages', 'crocina-forms' ); ?></span></h2></div>
				<div class="inside">
					<p class="crocina-inbox-unread-count" style="font-size:2rem;font-weight:600;margin:0;color:#2271b1;"><?php echo esc_html( number_format_i18n( $total_unread ) ); ?></p>
				</div>
			</div>

			<div class="postbox">
				<div class="postbox-header"><h2 class="hndle"><span><?php esc_html_e( 'Filter', 'crocina-forms' ); ?></span></h2></div>
				<div class="inside">
					<form method="get">
						<input type="hidden" name="page" value="crocina-forms-inbox" />
						<?php wp_nonce_field( 'crocina_inbox_filter', 'crocina_inbox_filter_nonce' ); ?>

						<label><?php esc_html_e( 'Filter by form', 'crocina-forms' ); ?>
							<select name="form_id" onchange="this.form.submit()" style="display:block;width:100%;margin-top:0.35rem;">
								<option value="0"><?php esc_html_e( 'All forms', 'crocina-forms' ); ?></option>
								<?php foreach ( $forms as $form ) : ?>
									<option value="<?php echo esc_attr( $form->ID ); ?>" <?php selected( $filter_form_id, $form->ID ); ?>><?php echo esc_html( get_the_title( $form ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>

						<label style="display:block;margin-top:0.75rem;"><?php esc_html_e( 'Status', 'crocina-forms' ); ?>
							<select name="is_read" onchange="this.form.submit()" style="display:block;width:100%;margin-top:0.35rem;">
								<option value="" <?php selected( $is_read_filter, '' ); ?>><?php esc_html_e( 'All messages', 'crocina-forms' ); ?></option>
								<option value="0" <?php selected( $is_read_filter, '0' ); ?>><?php esc_html_e( 'Unread only', 'crocina-forms' ); ?></option>
								<option value="1" <?php selected( $is_read_filter, '1' ); ?>><?php esc_html_e( 'Read only', 'crocina-forms' ); ?></option>
							</select>
						</label>
					</form>
				</div>
			</div>

			<?php if ( $total_unread > 0 ) : ?>
			<button type="button" class="button crocina-mark-all-read" data-form-id="<?php echo esc_attr( $filter_form_id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'crocina_mark_all_read' ) ); ?>">
				<?php esc_html_e( 'Mark all as read', 'crocina-forms' ); ?>
			</button>
			<?php endif; ?>
		</aside>

		<main style="flex:1;min-width:0;">
			<div class="tablenav top" style="margin-bottom:0.5rem;">
				<form method="get" class="alignright">
					<input type="hidden" name="page" value="crocina-forms-inbox" />
					<?php wp_nonce_field( 'crocina_inbox_log_search', 'crocina_inbox_log_search_nonce' ); ?>
					<label class="screen-reader-text"><?php esc_html_e( 'Search logs', 'crocina-forms' ); ?></label>
					<input type="search" name="s" value="<?php echo esc_attr( $log_search ); ?>" placeholder="<?php esc_attr_e( 'Search inbox...', 'crocina-forms' ); ?>" />
					<?php submit_button( __( 'Search', 'crocina-forms' ), 'button', 'submit', false ); ?>
				</form>
				<br class="clear" />
			</div>

			<form method="post"><?php
				if ( $table ) {
					$table->display();
				}
			?></form>
		</main>
	</div>

	<div class="crocina-quick-view-modal" aria-hidden="true">
		<div class="crocina-quick-view-dialog" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Quick view', 'crocina-forms' ); ?>">
			<div class="crocina-quick-view-header">
				<strong class="crocina-quick-view-title"></strong>
				<button type="button" class="button-link crocina-quick-view-close" aria-label="<?php esc_attr_e( 'Close', 'crocina-forms' ); ?>">×</button>
			</div>
			<div class="crocina-quick-view-meta">
				<span class="crocina-quick-view-date"></span>
				<span class="crocina-quick-view-ip"></span>
				<a class="crocina-quick-view-page" href="#" target="_blank" rel="noreferrer noopener"></a>
			</div>
			<div class="crocina-quick-view-body"></div>
		</div>
	</div>
</div>
