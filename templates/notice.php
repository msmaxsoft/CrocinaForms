<?php
defined( 'ABSPATH' ) || exit;

$data         = is_array( $data ?? null ) ? $data : array();
$status       = $data['status'] ?? '';
$message      = $data['message'] ?? '';
$submitted_at = $data['submitted_at'] ?? 0;
$settings     = is_array( $data['settings'] ?? null ) ? $data['settings'] : array();
?>
<div class="crocina-notice <?php echo esc_attr( 'sent' === $status ? 'crocina-notice-success' : 'crocina-notice-error' ); ?>">
	<?php echo esc_html( $message ); ?>
	<?php if ( $submitted_at ) : ?>
		<div class="crocina-notice-meta">
			<?php
			$format  = $settings['jalali_date_format'] ?? 'short';
			$display = Crocina_Forms_Core::format_jalali_display( $submitted_at, $format );
			printf( '%s %s', esc_html__( 'Submitted at:', 'crocina-forms' ), esc_html( $display ) );
			?>
		</div>
	<?php endif; ?>
</div>
