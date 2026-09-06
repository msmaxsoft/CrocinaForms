$ErrorActionPreference = 'Stop'
$path = 'C:\laragon\www\GhitHub\CrocinaForms\templates\notice.php'

$content = @'
<?php
defined( 'ABSPATH' ) || exit;

$data         = is_array( $data ?? null ) ? $data : array();
$status       = $data['status'] ?? '';
$message      = $data['message'] ?? '';
$submitted_at = $data['submitted_at'] ?? 0;

/*
 * The Jalali display string is pre-formatted by the caller and passed in as
 * 'submitted_at_jalali'. We intentionally do NOT call
 * Crocina_Forms_Core::format_jalali_display() here: that method is a non-static
 * instance method (it uses $this internally), so a static call fatals on PHP 8
 * ("Using $this when not in object context").
 */
$submitted_display = isset( $data['submitted_at_jalali'] ) ? (string) $data['submitted_at_jalali'] : '';
?>
<div class="crocina-notice <?php echo esc_attr( 'sent' === $status ? 'crocina-notice-success' : 'crocina-notice-error' ); ?>">
	<?php echo esc_html( $message ); ?>
	<?php if ( $submitted_at && '' !== $submitted_display ) : ?>
		<div class="crocina-notice-meta">
			<?php printf( '%s %s', esc_html__( 'Submitted at:', 'crocina-forms' ), esc_html( $submitted_display ) ); ?>
		</div>
	<?php endif; ?>
</div>
'@

$enc = New-Object System.Text.UTF8Encoding($false)
# Normalize to CRLF
$content = $content -replace "`r`n", "`n" -replace "`n", "`r`n"
[System.IO.File]::WriteAllText($path, $content, $enc)
Write-Output "OK rewrote notice.php"
