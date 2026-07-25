<?php
defined( 'ABSPATH' ) || exit;

$data         = is_array( $data ?? null ) ? $data : array();
$search_query = $data['search_query'] ?? '';
$total_forms  = $data['total_forms'] ?? 0;
$total_logs   = $data['total_logs'] ?? 0;
$table        = $data['table'] ?? null;
$daily_stats  = $data['daily_stats'] ?? array();
$stats_days   = absint( $data['stats_days'] ?? 7 );
?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Crocina Forms', 'crocina-forms' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=crocina_form' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New Form', 'crocina-forms' ); ?></a>

	<hr class="wp-header-end" />

	<?php
	/* ---- WP_List_Table ---- */
	if ( $table ) {
		$table->prepare_items();
		?>
		<form method="post"><?php
			$table->search_box( __( 'Search', 'crocina-forms' ), 'crocina-form-search' );
			$table->display();
		?></form>
		<?php
	} else {
		echo '<p>' . esc_html__( 'No forms found. Create your first form to get started.', 'crocina-forms' ) . '</p>';
	}
	?>
</div>
