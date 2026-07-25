<?php
/**
 * Apply crocina_fields caching improvements to Crocina_Forms_Core.
 *
 * Usage: php tools/implement-fields-cache.php
 *
 * Makes these changes to includes/class-crocina-core.php:
 *
 * 1. Rename get_cached_fields → public get_form_fields + add perf counters
 * 2. Add flush_form_fields_cache method
 * 3. Update on_updated_post_meta to also flush fields cache
 * 4. Replace direct get_post_meta in process_submission with cached version
 * 5. Add preload_form_fields method
 * 6. Add preload call in detect_shortcode_in_posts/content/text
 * 7. Add fields warm to warm_cache
 * 8. Add fields perf counters
 */

if ( 'cli' !== PHP_SAPI ) {
    die( 'CLI only.' );
}

$file = __DIR__ . '/../includes/class-crocina-core.php';
$content = file_get_contents( $file );
if ( false === $content ) {
    die( "Failed to read file.\n" );
}

$original = $content;

// ---- 1. Rename get_cached_fields → public get_form_fields in render_shortcode() ----
$content = str_replace(
    '$fields = $this->get_cached_fields( $form->ID );',
    '$fields = $this->get_form_fields( $form->ID );',
    $content
);

// ---- 2. Replace direct get_post_meta in process_submission() with cached ----
// Find: $fields = get_post_meta( $form_id, 'crocina_fields', true ); inside process_submission
// The first occurrence after the handlign of nonce check is the one in process_submission().
$content = preg_replace(
    '/\$fields \= get_post_meta\( \$form_id, \'crocina_fields\', true \);\s+\$fields \= is_array\( \$fields \) \? \$fields : array\(\);\s+if \( count\( \$fields \) > 50 \)/s',
    '$fields = $this->get_form_fields( $form_id );
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}
		if ( count( $fields ) > 50 )',
    $content
);

// ---- 3. Add flush_form_fields_cache method ----
// Place it after get_form_fields method ends (search for the "public function get_form_design" start)
$get_form_design_start = strpos( $content, 'public function get_form_design(' );
$get_form_fields_end = strrpos( substr( $content, 0, $get_form_design_start ), 'return $fields;' );
$after_return = strpos( $content, 'return $fields;', $get_form_fields_end );
$brace_pos = strpos( $content, '}', $after_return + 1 );

$flush_method = "\tpublic function flush_form_fields_cache( \$form_id = 0 ) {\n\n\n\n\t\tif ( \$form_id ) {\n\n\n\n\t\t\t\$form_id = absint( \$form_id );\n\n\n\n\t\t\tunset( \$this->form_design_cache[ 'fields_' . \$form_id ] );\n\n\n\n\t\t\twp_cache_delete( 'crocina_fields_' . \$form_id, self::\$cache_group );\n\n\n\n\t\t\tdelete_transient( self::\$transient_prefix . 'fields_' . \$form_id );\n\n\n\n\t\t}\n\n\n\n\t}\n\n\n\n\n\n\n\t";

$content = substr_replace( $content, $flush_method, $brace_pos + 1, 0 );

// ---- 4. Update on_updated_post_meta to also flush fields cache ----
$content = str_replace(
    "if ( 'crocina_form_design' === \$meta_key ) {\n\n\n\n\t\t\t\$this->flush_form_design_cache( \$object_id );\n\n\n\n\t\t}",
    "if ( 'crocina_form_design' === \$meta_key ) {\n\n\n\n\t\t\t\$this->flush_form_design_cache( \$object_id );\n\n\n\n\t\t}\n\n\n\n\t\tif ( 'crocina_fields' === \$meta_key ) {\n\n\n\n\t\t\t\$this->flush_form_fields_cache( \$object_id );\n\n\n\n\t\t}",
    $content
);

// ---- 5. Add preload_form_fields method after preload_form_designs ----
// Find preload_form_designs end and insert after
$preload_designs_end = strpos( $content, "public function preload_global_settings" );
// Actually find the } that closes preload_form_designs
$search_start = strpos( $content, "public function preload_form_designs( array" );
$search_end = strpos( $content, "public function preload_global_settings", $search_start );
$preload_global_start = $search_end;
$closing_brace_before_global = strrpos( substr( $content, 0, $preload_global_start ), '}' );
// Find the previous occurrence before preload_global_settings
$preload_designs_close = strrpos( substr( $content, 0, $preload_global_start ), '}' );
// Find the previous brace before that (the closing brace of preload_form_designs)
$preload_designs_end = strrpos( substr( $content, 0, $preload_designs_close ), '}' );
// Actually let me look for the exact pattern
$preload_fields_method = '
	public function preload_form_fields( array $form_ids ) {



		if ( empty( $form_ids ) ) {



			return;



		}



		$form_ids = array_map( \'absint\', $form_ids );



		$form_ids = array_values( array_unique( array_filter( $form_ids ) ) );



		if ( empty( $form_ids ) ) {



			return;



		}



		// Skip IDs already in memory cache.



		$miss_ids = array();



		foreach ( $form_ids as $id ) {



			if ( ! array_key_exists( \'fields_\' . $id, $this->form_design_cache ) ) {



				$miss_ids[] = $id;



			}



		}



		if ( empty( $miss_ids ) ) {



			return;



		}



		// Batch-load ALL meta for ALL pending form posts in ONE query.



		update_meta_cache( \'post\', $miss_ids );



		$this->perf_counters[\'fields_preloaded\'] += count( $miss_ids );



		// Extract crocina_fields meta and populate both caches.



		foreach ( $miss_ids as $id ) {



			$fields = get_post_meta( $id, \'crocina_fields\', true );



			$fields = is_array( $fields ) ? $fields : array();



			$this->form_design_cache[ \'fields_\' . $id ] = $fields;



			wp_cache_set( \'crocina_fields_\' . $id, $fields, self::$cache_group );



			if ( ! $this->uses_persistent_cache() ) {



				set_transient( self::$transient_prefix . \'fields_\' . $id, $fields, self::$transient_ttl );



			}



		}



	}

'

;
// Insert before preload_global_settings
$preload_global = strpos( $content, 'public function preload_global_settings()' );
$content = substr_replace( $content, $preload_fields_method . "\n\n\n\n\t", $preload_global, 0 );

// ---- 6. Add preload_form_fields calls in detect_shortcode_* alongside design preloads ----
$content = str_replace(
    "if ( ! empty( \$all_ids ) ) {\n\n\n\n\t\t\t\$this->preload_form_designs( \$all_ids );\n\n\n\n\t\t}",
    "if ( ! empty( \$all_ids ) ) {\n\n\n\n\t\t\t\$this->preload_form_designs( \$all_ids );\n\n\n\n\t\t\t\$this->preload_form_fields( \$all_ids );\n\n\n\n\t\t}",
    $content
);

// Replace in detect_shortcode_in_content
$content = preg_replace(
    '/if \( \! empty\( \$ids \) \)\s*\{[^}]*\$this->preload_form_designs\( \$ids \);[^}]*\}/s',
    'if ( ! empty( $ids ) ) {
			$this->preload_form_designs( $ids );
			$this->preload_form_fields( $ids );
		}',
    $content
);

// Actually the content function has multiple preload_form_designs calls. Let me use a more precise approach.
// I'll replace in detect_shortcode_in_text too
$content = str_replace(
    "\t\t\t\$this->preload_form_designs( \$ids );\n\n\n\n\t\t\t// Preload global settings",
    "\t\t\t\$this->preload_form_designs( \$ids );\n\n\n\n\t\t\t\$this->preload_form_fields( \$ids );\n\n\n\n\t\t\t// Preload global settings",
    $content
);

// ---- 7. Add fields warm to warm_cache ----
$content = str_replace(
    "\t\t\$this->get_global_settings();\n\n\n\n\t\t\$this->get_form_design( \$post_id );",
    "\t\t\$this->get_global_settings();\n\n\n\n\t\t\$this->get_form_design( \$post_id );\n\n\n\n\t\t\$this->get_form_fields( \$post_id );",
    $content
);

// ---- 8. Add fields perf counters to get_cache_stats() ----
$content = str_replace(
    "\tprivate \$perf_counters = array(\n\n\n\n\t\t'design_preloaded'    => 0,  // Forms batch-loaded via preload_form_designs()\n\n\n\n\t\t'design_db_calls'     => 0,  // Individual get_post_meta() in get_form_design()\n\n\n\n\t\t'settings_db_calls'   => 0,  // DB reads of global settings\n\n\n\n\t\t'settings_cache_hits' => 0,  // In-memory, object-cache, or transient hits\n\n\n\n\t);",
    "\tprivate \$perf_counters = array(\n\n\n\n\t\t'design_preloaded'    => 0,  // Forms batch-loaded via preload_form_designs()\n\n\n\n\t\t'design_db_calls'     => 0,  // Individual get_post_meta() in get_form_design()\n\n\n\n\t\t'settings_db_calls'   => 0,  // DB reads of global settings\n\n\n\n\t\t'settings_cache_hits' => 0,  // In-memory, object-cache, or transient hits\n\n\n\n\t\t'fields_preloaded'    => 0,  // Forms batch-loaded via preload_form_fields()\n\n\n\n\t\t'fields_db_calls'     => 0,  // Individual get_post_meta() in get_form_fields()\n\n\n\n\t\t'fields_cache_hits'   => 0,  // Fields cache hits\n\n\n\n\t);",
    $content
);

if ( $content === $original ) {
    echo "❌ No changes made — file content did not match patterns.\n";
    exit( 1 );
}

file_put_contents( $file, $content );

echo "✅ Changes applied to class-crocina-core.php\n";
echo "   File size: " . number_format( strlen( $content ) ) . " bytes\n";
echo "   Changes: " . ( strlen( $content ) - strlen( $original ) ) . " bytes added\n";

// Validate syntax
exec( 'php -l ' . escapeshellarg( $file ) . ' 2>&1', $output, $exit_code );
if ( 0 !== $exit_code ) {
    echo "❌ Syntax error in modified file:\n";
    echo implode( "\n", $output ) . "\n";
    exit( 1 );
}
echo "✅ PHP syntax check passed.\n";
