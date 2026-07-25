<?php
/**
 * POT Generator — extracts all translatable strings from Crocina Forms.
 *
 * Usage: php tools/extract-strings.php
 *
 * Scans all .php and .js files in includes/, templates/, assets/, and the
 * main plugin file, then generates a complete .pot file suitable for
 * translation tools (Poedit, GlotPress, etc.) and WordPress.org.
 *
 * @package Crocina_Forms
 */

if ( 'cli' !== PHP_SAPI ) {
	die( 'CLI only.' );
}

define( 'PLUGIN_DIR', realpath( __DIR__ . '/..' ) );
define( 'OUTPUT', PLUGIN_DIR . '/languages/crocina-forms.pot' );

/* ------------------------------------------------------------------ */
/*  File discovery (uses scandir — works on all platforms)            */
/* ------------------------------------------------------------------ */

/**
 * Scan a flat directory for files with a given extension, returning
 * paths relative to PLUGIN_DIR.
 *
 * Ignores hidden files (starting with '.').
 *
 * @param string $sub_dir  Sub-directory name relative to PLUGIN_DIR.
 * @param string $ext      File extension without dot (e.g. 'php').
 * @return string[] Relative paths.
 */
function scan_dir( $sub_dir, $ext ) {
	$dir = PLUGIN_DIR . '/' . $sub_dir;
	if ( ! is_dir( $dir ) ) {
		return array();
	}

	$files   = array();
	$ext_len = strlen( $ext ) + 1; // +1 for the dot.

	$entries = scandir( $dir );
	if ( false === $entries ) {
		return array();
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry[0] ) {
			continue; // skip hidden files and . / ..
		}
		if ( substr( $entry, -$ext_len ) === '.' . $ext ) {
			$files[] = $sub_dir . '/' . $entry;
		}
	}

	sort( $files );
	return $files;
}

/* ------------------------------------------------------------------ */
/*  String extraction (regex)                                         */
/* ------------------------------------------------------------------ */

/**
 * Extract all i18n function calls from a file.
 *
 * Uses the same well-tested regex pattern from TestTranslations.php
 * that correctly identifies all gettext calls in the codebase.
 *
 * Supports: __, _e, esc_html__, esc_attr__, esc_html_e, esc_attr_e,
 * and separately _n / _nx (plural).
 *
 * Returns an associative array keyed by msgid (or msgid . "\0" . plural).
 *
 * @param string $path Absolute path to the file.
 * @return array{msgid:string,plural:string,line:int,file:string}[]
 */
function extract_strings( $path ) {
	$content = file_get_contents( $path ); // phpcs:ignore
	if ( false === $content || '' === $content ) {
		return array();
	}

	// Normalise to forward slashes, then strip the plugin directory prefix
	// to produce portable relative paths (e.g. "includes/class-crocina-ajax.php").
	$normalised_path = str_replace( '\\', '/', $path );
	$normalised_dir  = str_replace( '\\', '/', PLUGIN_DIR );
	$prefix          = rtrim( $normalised_dir, '/' ) . '/';
	$relative        = strpos( $normalised_path, $prefix ) === 0
		? substr( $normalised_path, strlen( $prefix ) )
		: basename( $normalised_path );

	$result = array(); // key => info
	$lines  = explode( "\n", $content );

	// ---- Standard functions: __, _e, esc_html__, etc. ----
	$funcs  = array( '__', '_e', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e' );
	$domain = preg_quote( 'crocina-forms', '/' );

	foreach ( $funcs as $func ) {
		$pattern = '/' . preg_quote( $func, '/' ) . '\s*\(\s*'
			. '([\'"])(.+?)\1\s*'
			. '(?:,\s*[\'"](' . $domain . '|default)[\'"]\s*)?'
			. '\)/s';

		if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$text = $m[2];
				if ( '' === trim( $text ) ) {
					continue;
				}
				$key = $text;
				if ( ! isset( $result[ $key ] ) ) {
					$line = 0;
					foreach ( $lines as $li => $l ) {
						if ( false !== strpos( $l, $m[0] ) ) {
							$line = $li + 1;
							break;
						}
					}
					$result[ $key ] = array(
						'msgid'  => $text,
						'plural' => '',
						'line'   => $line,
						'file'   => $relative,
					);
				}
			}
		}
	}

	// ---- Plural functions: _n, _nx ----
	// Pattern: _n( 'single', 'plural', $count, 'domain' )
	$n_pattern = '/_n[x]?\s*\(\s*'
		. '([\'"])(.+?)\1\s*,\s*'
		. '([\'"])(.+?)\3\s*,\s*'
		. '\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\s*,\s*'
		. '[\'"](' . $domain . ')[\'"]\s*'
		. '\)/s';

	if ( preg_match_all( $n_pattern, $content, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $m ) {
			$single = $m[2];
			$plural = $m[4];
			if ( '' === trim( $single ) || '' === trim( $plural ) ) {
				continue;
			}
			$key = $single . "\0" . $plural;
			if ( ! isset( $result[ $key ] ) ) {
				$line = 0;
				foreach ( $lines as $li => $l ) {
					if ( false !== strpos( $l, $m[0] ) ) {
						$line = $li + 1;
						break;
					}
				}
				$result[ $key ] = array(
					'msgid'  => $single,
					'plural' => $plural,
					'line'   => $line,
					'file'   => $relative,
				);
			}
		}
	}

	return $result;
}

/* ------------------------------------------------------------------ */
/*  PO file helpers                                                   */
/* ------------------------------------------------------------------ */

/**
 * Escape a string for use in a PO file.
 *
 * Handles backslashes, double quotes, and control characters.
 *
 * @param string $str Raw UTF-8 string.
 * @return string Escaped string safe for msgid / msgstr.
 */
function esc_po( $str ) {
	$str = str_replace( '\\', '\\\\', $str );
	$str = str_replace( '"', '\\"', $str );
	$str = str_replace( "\n", '\\n', $str );
	$str = str_replace( "\r", '\\r', $str );
	$str = str_replace( "\t", '\\t', $str );
	return $str;
}

/* ------------------------------------------------------------------ */
/*  Main                                                              */
/* ------------------------------------------------------------------ */

// ---- Collect files ----
$file_groups = array_merge(
	scan_dir( 'includes', 'php' ),
	scan_dir( 'templates', 'php' ),
	scan_dir( 'templates/admin', 'php' ),
	scan_dir( 'templates/fields', 'php' ),
	scan_dir( 'assets', 'js' ),
	array( 'crocina-forms.php' )
);

// ---- Extract all strings ----
$all_strings = array(); // key => info (deduplicated)

// Track file:line references per msgid.
$references = array(); // msgkey => string[]

foreach ( $file_groups as $relative_path ) {
	$path     = PLUGIN_DIR . '/' . $relative_path;
	$strings  = extract_strings( $path );
	$msgkey   = $relative_path;

	foreach ( $strings as $key => $info ) {
		if ( ! isset( $all_strings[ $key ] ) ) {
			$all_strings[ $key ] = $info;
		}
		$ref = $info['file'] . ':' . $info['line'];
		if ( ! isset( $references[ $key ] ) ) {
			$references[ $key ] = array();
		}
		if ( ! in_array( $ref, $references[ $key ], true ) ) {
			$references[ $key ][] = $ref;
		}
	}
}

ksort( $all_strings );

// ---- Count by function type ----
$count_simple = 0;
$count_plural = 0;
foreach ( $all_strings as $info ) {
	if ( $info['plural'] ) {
		$count_plural++;
	} else {
		$count_simple++;
	}
}
$total       = count( $all_strings );
$file_count  = count( $file_groups );

// ---- Read plugin version ----
$version = '0.1.0';
$main_file = PLUGIN_DIR . '/crocina-forms.php';
if ( is_file( $main_file ) ) {
	$header = file_get_contents( $main_file ); // phpcs:ignore
	if ( preg_match( '/Version:\s*([\d.]+)/', $header, $v ) ) {
		$version = $v[1];
	}
}

/* ================================================================== */
/*  Generate .pot output                                              */
/* ================================================================== */

$pot  = '# Crocina Forms — Translation Template' . "\n";
$pot .= '# Copyright (C) ' . gmdate( 'Y' ) . ' Crocina Forms' . "\n";
$pot .= '# This file is distributed under the same license as the plugin.' . "\n";
$pot .= '# ' . str_repeat( '-', 72 ) . "\n";
$pot .= '# ' . PHP_EOL;
$pot .= '# ' . $total . ' translatable strings extracted on ' . gmdate( 'Y-m-d H:i:s' ) . "\n";
$pot .= '# ' . PHP_EOL;
$pot .= 'msgid ""' . "\n";
$pot .= 'msgstr ""' . "\n";
$pot .= '"Project-Id-Version: Crocina Forms ' . $version . '\n"' . "\n";
$pot .= '"POT-Creation-Date: ' . gmdate( 'Y-m-d H:i:sO' ) . '\n"' . "\n";
$pot .= '"MIME-Version: 1.0\n"' . "\n";
$pot .= '"Content-Type: text/plain; charset=UTF-8\n"' . "\n";
$pot .= '"Content-Transfer-Encoding: 8bit\n"' . "\n";
$pot .= '"Language: en_US\n"' . "\n";
$pot .= '"Plural-Forms: nplurals=2; plural=n != 1;\n"' . "\n";
$pot .= '"X-Generator: Crocina Forms POT Generator\n"' . "\n";
$pot .= "\n";

// ---- Entries ----
foreach ( $all_strings as $key => $info ) {
	// Reference lines.
	$refs = $references[ $key ] ?? array( $info['file'] . ':' . $info['line'] );
	foreach ( $refs as $ref ) {
		$pot .= '#: ' . $ref . "\n";
	}

	// msgid (and msgid_plural for plurals).
	$pot .= 'msgid "' . esc_po( $info['msgid'] ) . '"' . "\n";

	if ( $info['plural'] ) {
		$pot .= 'msgid_plural "' . esc_po( $info['plural'] ) . '"' . "\n";
		$pot .= 'msgstr[0] ""' . "\n";
		$pot .= 'msgstr[1] ""' . "\n";
	} else {
		$pot .= 'msgstr ""' . "\n";
	}

	$pot .= "\n";
}

// ---- Write ----
$written = file_put_contents( OUTPUT, $pot ); // phpcs:ignore
if ( false === $written ) {
	echo "  ✗ Failed to write POT file.\n";
	exit( 1 );
}

$bytes_fmt = number_format( $written );

echo <<<REPORT
┌──────────────────────────────────────────────────────────────┐
│  Crocina Forms — POT Generator                                │
├──────────────────────────────────────────────────────────────┤
│  Source files scanned:  {$file_count}                              │
│  Total strings:         {$total}                               │
│  Simple strings:        {$count_simple}                               │
│  Plural strings:        {$count_plural}                                 │
│  File size:             {$bytes_fmt} bytes                      │
├──────────────────────────────────────────────────────────────┤
│  Output:              languages/crocina-forms.pot           │
│  Version:             {$version}                                   │
└──────────────────────────────────────────────────────────────┘

REPORT;
