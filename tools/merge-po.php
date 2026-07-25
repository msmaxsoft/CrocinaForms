<?php
/**
 * Merge POT into existing PO file.
 *
 * Preserves all existing translations and adds new strings from the POT
 * as untranslated entries. Also identifies garbled/placeholder translations
 * (those containing question marks) for manual review.
 *
 * Usage: php tools/merge-po.php
 */

if ( 'cli' !== PHP_SAPI ) {
    die( 'CLI only.' );
}

define( 'PLUGIN_DIR', realpath( __DIR__ . '/..' ) );

/**
 * Parse a PO/POT file into an associative array keyed by msgid.
 */
function parse_po( $path ) {
    $content = file_get_contents( $path );
    if ( false === $content ) {
        return array();
    }

    $entries = array();
    $lines   = explode( "\n", $content );
    $current = null;

    foreach ( $lines as $line ) {
        if ( preg_match( '/^msgid "((?:[^"\\\\]|\\\\.)*)"/', $line, $m ) ) {
            // Save previous entry.
            if ( null !== $current ) {
                $entries[ $current['msgid'] ] = $current;
            }
            $current = array(
                'msgid'       => stripcslashes( $m[1] ),
                'msgstr'      => '',
                'msgstr_pl'   => array(),
                'files'       => array(),
                'flags'       => array(),
            );
        } elseif ( null !== $current && preg_match( '/^msgstr "((?:[^"\\\\]|\\\\.)*)"/', $line, $m ) ) {
            $current['msgstr'] = stripcslashes( $m[1] );
        } elseif ( null !== $current && preg_match( '/^msgstr\[(\d+)\] "((?:[^"\\\\]|\\\\.)*)"/', $line, $m ) ) {
            $current['msgstr_pl'][ (int) $m[1] ] = stripcslashes( $m[2] );
        } elseif ( null !== $current && preg_match( '/^#: (.+)/', $line, $m ) ) {
            $current['files'][] = $m[1];
        } elseif ( null !== $current && preg_match( '/^#,\s*(.+)/', $line, $m ) ) {
            $current['flags'] = array_map( 'trim', explode( ',', $m[1] ) );
        } elseif ( null !== $current && '' === trim( $line ) ) {
            // Empty line between entries — continue.
        }
    }

    if ( null !== $current ) {
        $entries[ $current['msgid'] ] = $current;
    }

    return $entries;
}

/**
 * Escape a string for PO output.
 */
function esc_po( $str ) {
    $str = str_replace( '\\', '\\\\', $str );
    $str = str_replace( '"', '\\"', $str );
    $str = str_replace( "\n", '\\n', $str );
    $str = str_replace( "\r", '\\r', $str );
    $str = str_replace( "\t", '\\t', $str );
    return $str;
}

/**
 * Check if a translation is garbled (contains placeholder ? characters).
 */
function is_garbled( $str ) {
    return false !== strpos( $str, '?????' ) || preg_match( '/[؟?]{3,}/', $str );
}

// ---- Main ----

$pot_path = PLUGIN_DIR . '/languages/crocina-forms.pot';
$po_path  = PLUGIN_DIR . '/languages/crocina-forms-fa_IR.po';

if ( ! is_file( $pot_path ) ) {
    echo "❌ POT file not found: $pot_path\n";
    echo "   Run tools/extract-strings.php first.\n";
    exit( 1 );
}

if ( ! is_file( $po_path ) ) {
    echo "❌ PO file not found: $po_path\n";
    exit( 1 );
}

echo "📖 Parsing POT... ";
$pot_entries = parse_po( $pot_path );
echo count( $pot_entries ) . " entries\n";

echo "📖 Parsing current PO... ";
$po_entries = parse_po( $po_path );
echo count( $po_entries ) . " entries\n";

// ---- Build merged output preserving header ----

$po_header = '';
$po_content = file_get_contents( $po_path );
if ( preg_match( '/^msgid ""\nmsgstr ""\n(?:".*"\n)*/', $po_content, $h ) ) {
    $po_header = $h[0];
}

// Use POT header (more up-to-date).
$pot_header = '';
if ( preg_match( '/^msgid ""\nmsgstr ""\n(?:".*"\n)*/', file_get_contents( $pot_path ), $h ) ) {
    $pot_header = $h[0];
}

$output = $pot_header . "\n";

$new_count      = 0;
$garbled_count  = 0;
$untranslated   = array();
$newly_added    = array();
$existing_count = 0;

foreach ( $pot_entries as $msgid => $entry ) {
    $output .= '#: ' . implode( ', ', array_unique( $entry['files'] ) ) . "\n";

    if ( isset( $po_entries[ $msgid ] ) ) {
        $po_entry = $po_entries[ $msgid ];
        $trans    = $po_entry['msgstr'];

        // Check if existing translation is garbled.
        if ( is_garbled( $trans ) ) {
            $garbled_count++;
            $trans = '';
            $output .= '#, fuzzy' . "\n";
            $untranslated[] = $msgid;
        } elseif ( '' === $trans ) {
            $untranslated[] = $msgid;
        }

        $output .= 'msgid "' . esc_po( $msgid ) . '"' . "\n";
        if ( '' !== $trans ) {
            $existing_count++;
            $output .= 'msgstr "' . esc_po( $trans ) . '"' . "\n";
        } else {
            $output .= 'msgstr ""' . "\n";
        }
    } else {
        // New string from POT.
        $new_count++;
        $newly_added[] = $msgid;
        $untranslated[] = $msgid;
        $output .= 'msgid "' . esc_po( $msgid ) . '"' . "\n";
        $output .= 'msgstr ""' . "\n";
    }

    $output .= "\n";
}

// Write the merged .po file.
$written = file_put_contents( $po_path, $output );
if ( false === $written ) {
    echo "❌ Failed to write PO file.\n";
    exit( 1 );
}

echo "\n✅ PO file updated: $po_path\n";
echo "\n--- Statistics ---\n";
echo "  Existing translations preserved: $existing_count\n";
echo "  Newly added (untranslated):      $new_count\n";
echo "  Garbled translations flagged:    $garbled_count\n";
echo "  Total untranslated:              " . count( $untranslated ) . "\n";

if ( ! empty( $newly_added ) ) {
    echo "\n--- New strings (need translation) ---\n";
    foreach ( $newly_added as $s ) {
        echo "  • $s\n";
    }
}

if ( $garbled_count > 0 ) {
    echo "\n--- Garbled translations flagged (marked fuzzy) ---\n";
    echo "  These contain '?????' placeholders and need manual review.\n";
}
