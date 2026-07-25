<?php
defined( 'ABSPATH' ) || exit;

$data       = is_array( $data ?? null ) ? $data : array();
$exists     = ! empty( $data['exists'] );
$log_size   = absint( $data['log_size'] ?? 0 );
$lines      = is_array( $data['lines'] ?? null ) ? $data['lines'] : array();
$clear_nonce = $data['clear_nonce'] ?? '';
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Event Log', 'crocina-forms' ); ?></h1>
	<hr class="wp-header-end" />
	<div id="poststuff">
		<div class="postbox" style="padding:0.75rem 1rem;margin:1rem 0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
			<div style="display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap;font-size:13px;color:#4c5468;">
				<?php if ( $exists ) : ?>
					<span style="display:inline-flex;align-items:center;gap:0.3rem;">
						<?php esc_html_e( 'File size:', 'crocina-forms' ); ?>
						<strong><?php echo esc_html( size_format( $log_size ) ); ?></strong>
					</span>
					<span style="display:inline-flex;align-items:center;gap:0.3rem;">
						<?php esc_html_e( 'Lines shown:', 'crocina-forms' ); ?>
						<strong><?php echo count( $lines ); ?></strong>
					</span>
					<span style="display:inline-flex;align-items:center;gap:0.3rem;">
						<?php esc_html_e( 'Path:', 'crocina-forms' ); ?>
						<code>/wp-content/uploads/crocina-forms/events.log</code>
					</span>
				<?php else : ?>
					<span style="display:inline-flex;align-items:center;gap:0.3rem;">
						<?php esc_html_e( 'No log file found.', 'crocina-forms' ); ?>
					</span>
				<?php endif; ?>
			</div>

			<div style="display:flex;align-items:center;gap:0.5rem;">
				<button type="button" id="crocina-refresh-log" class="button"><?php esc_html_e( 'Refresh', 'crocina-forms' ); ?></button>
				<button
					type="button"
					id="crocina-clear-log"
					class="button button-primary"
					data-nonce="<?php echo esc_attr( $clear_nonce ); ?>"
					data-loading-text="<?php esc_attr_e( 'Clearing...', 'crocina-forms' ); ?>"
					data-default-text="<?php esc_attr_e( 'Clear log', 'crocina-forms' ); ?>"
					data-confirm="<?php esc_attr_e( 'Are you sure you want to delete the entire event log?', 'crocina-forms' ); ?>"
				><?php esc_html_e( 'Clear log', 'crocina-forms' ); ?></button>
			</div>
		</div>

		<div id="crocina-event-log-result" role="status" aria-live="polite" style="display:none;margin-bottom:0.75rem;padding:0.5rem 0.75rem;border-radius:4px;font-size:13px;"></div>

		<?php if ( empty( $lines ) ) : ?>
			<div class="postbox" style="padding:2rem 1rem;text-align:center;">
				<span class="dashicons dashicons-flag" aria-hidden="true" style="font-size:36px;width:36px;height:36px;color:#8c8f94;"></span>
				<p style="margin:0.5rem 0 0;font-size:13px;color:#646970;">
					<?php esc_html_e( 'No log entries yet. Enable event logging in Settings to start collecting events.', 'crocina-forms' ); ?>
				</p>
			</div>
		<?php else : ?>
			<div class="postbox" style="padding:0;overflow:hidden;">
				<div style="background:#1e1e1e;padding:0.75rem 1rem;overflow:auto;max-height:70vh;">
					<pre style="margin:0;white-space:pre-wrap;word-break:break-all;font-size:12px;line-height:1.7;color:#d4d4d4;font-family:Consolas,Monaco,'Courier New',monospace;"><code><?php
					// Reverse so newest is on top.
					$reversed = array_reverse( $lines );
					foreach ( $reversed as $index => $line ) {
						$style = '';
						if ( false !== strpos( $line, 'form_submitted' ) ) {
							$style = 'color:#6abf69;';
						} elseif ( false !== strpos( $line, 'notification.sent' ) ) {
							$style = 'color:#64b5f6;';
						} elseif ( false !== strpos( $line, 'log.created' ) ) {
							$style = 'color:#ce93d8;';
						} elseif ( false !== strpos( $line, '[error]' ) || false !== strpos( $line, 'error' ) ) {
							$style = 'color:#f0625c;background:rgba(240,98,92,0.08);border-radius:2px;';
						}
						$line_num = count( $reversed ) - $index;
						echo '<span style="display:block;' . esc_attr( $style ) . '">';
						echo '<span style="color:#555;user-select:none;margin-right:0.5rem;">' . esc_html( sprintf( '%4d', $line_num ) ) . '</span> ';
						echo esc_html( $line );
						echo "</span>\n";
					}
					?></code></pre>
				</div>
			</div>
		<?php endif; ?>
	</div>
</div>
