<?php
/**
 * Find missing translations by comparing code strings against the .po file.
 *
 * Usage: php tools/find-missing.php
 *
 * Reads the generated .pot (or re-extracts if missing), parses the existing
 * .po translations, and produces a structured report of:
 *   - Strings missing from .po entirely
 *   - Strings in .po but untranslated (empty msgstr)
 *   - Strings with garbled translations (?????)
 *   - Successfully translated strings (count only)
 *
 * Optional flags:
 *   --json       Output in JSON format for machine processing.
 *   --show-all   Show ALL sections (default: only untranslated + garbled).
 *   --locale     PO file locale (default: fa_IR).
 */

if ( 'cli' !== PHP_SAPI ) {
    die( 'CLI only.' );
}

define( 'PLUGIN_DIR', realpath( __DIR__ . '/..' ) );

// ---- Parse CLI args ----
$show_json  = in_array( '--json', $argv, true );
$show_all   = in_array( '--show-all', $argv, true );
$locale     = 'fa_IR';

foreach ( $argv as $arg ) {
    if ( 0 === strpos( $arg, '--locale=' ) ) {
        $locale = substr( $arg, 9 );
    }
}

$pot_path    = PLUGIN_DIR . "/languages/crocina-forms.pot";
$po_path     = PLUGIN_DIR . "/languages/crocina-forms-{$locale}.po";

// ---- If no .pot, extract it first ----
if ( ! is_file( $pot_path ) ) {
    fwrite( STDERR, "POT file not found. Run tools/extract-strings.php first.\n" );
    exit( 1 );
}

if ( ! is_file( $po_path ) ) {
    fwrite( STDERR, "PO file not found: {$po_path}\n" );
    exit( 1 );
}

// ======================================================================
//  1. Parse POT → list of expected msgids
// ======================================================================
function parse_pot_strings( $path ) {
    $content = file_get_contents( $path );
    $strings = array(); // msgid => ['file' => 'path:line', 'plural' => '']

    $lines = explode( "\n", $content );
    $current_file  = '';
    $current_id    = '';
    $current_plural = '';

    foreach ( $lines as $line ) {
        if ( preg_match( '/^#: (.+)/', $line, $m ) ) {
            $current_file = $m[1];
        } elseif ( preg_match( '/^msgid "((?:[^"\\\\]|\\\\.)*)"/', $line, $m ) ) {
            $current_id = stripcslashes( $m[1] );
        } elseif ( preg_match( '/^msgid_plural "((?:[^"\\\\]|\\\\.)*)"/', $line, $m ) ) {
            $current_plural = stripcslashes( $m[1] );
        } elseif ( '' === trim( $line ) && '' !== $current_id ) {
            if ( ! isset( $strings[ $current_id ] ) ) {
                $strings[ $current_id ] = array(
                    'file'   => $current_file,
                    'plural' => $current_plural,
                );
            }
            $current_id    = '';
            $current_plural = '';
        }
    }

    return $strings;
}

// ======================================================================
//  2. Parse PO → get translations
// ======================================================================
function parse_po_translations( $path ) {
    $content = file_get_contents( $path );
    $entries = array();

    // Match simple msgid/msgstr pairs
    preg_match_all(
        '/^msgid "((?:[^"\\\\]|\\\\.)*)"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"/m',
        $content, $simple, PREG_SET_ORDER
    );
    foreach ( $simple as $m ) {
        $id       = stripcslashes( $m[1] );
        $str      = stripcslashes( $m[2] );
        $entries[ $id ] = array(
            'translation' => $str,
            'is_plural'   => false,
        );
    }

    // Match plural msgstr[0] entries
    preg_match_all(
        '/^msgid "((?:[^"\\\\]|\\\\.)*)"\nmsgstr\[0\] "((?:[^"\\\\]|\\\\.)*)"/m',
        $content, $plural, PREG_SET_ORDER
    );
    foreach ( $plural as $m ) {
        $id  = stripcslashes( $m[1] );
        $str = stripcslashes( $m[2] );
        if ( ! isset( $entries[ $id ] ) ) {
            $entries[ $id ] = array(
                'translation' => $str,
                'is_plural'   => true,
            );
        }
    }

    return $entries;
}

// ======================================================================
//  3. Detect garbled translations
// ======================================================================
function is_garbled( $str ) {
    return preg_match( '/[؟?]{3,}/', $str );
}

// ======================================================================
//  4. Compare
// ======================================================================
$expected   = parse_pot_strings( $pot_path );
$translations = parse_po_translations( $po_path );

$missing     = array(); // in code but not in .po at all
$untranslated = array(); // in .po but msgstr empty
$garbled     = array(); // msgstr has ????? patterns
$translated  = array(); // valid translations

foreach ( $expected as $msgid => $info ) {
    if ( ! isset( $translations[ $msgid ] ) ) {
        $missing[ $msgid ] = $info;
        continue;
    }

    $trans = $translations[ $msgid ]['translation'];

    if ( '' === $trans ) {
        $untranslated[ $msgid ] = $info;
    } elseif ( is_garbled( $trans ) ) {
        $garbled[ $msgid ] = $info + array( 'garbled_value' => $trans );
    } else {
        $translated[ $msgid ] = $info;
    }
}

// ======================================================================
//  5. Report
// ======================================================================

if ( $show_json ) {
    echo json_encode( array(
        'summary' => array(
            'total_in_code'     => count( $expected ),
            'missing_from_po'   => count( $missing ),
            'untranslated'      => count( $untranslated ),
            'garbled'           => count( $garbled ),
            'translated_ok'     => count( $translated ),
            'coverage_pct'      => count( $expected ) > 0
                ? round( count( $translated ) / count( $expected ) * 100, 1 )
                : 0,
        ),
        'missing'     => array_keys( $missing ),
        'untranslated' => array_keys( $untranslated ),
        'garbled'     => array_map( function( $id, $info ) {
            return array( 'msgid' => $id, 'garbled_value' => $info['garbled_value'] );
        }, array_keys( $garbled ), $garbled ),
        'locale'      => $locale,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
    exit( 0 );
}

// ---- Text output ----
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║   Crocina Forms — Missing Translations Report            ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

$total   = count( $expected );
$covered = count( $translated );
$pct     = $total > 0 ? round( $covered / $total * 100, 1 ) : 0;

echo "  Locale:              {$locale}\n";
echo "  PO file:             " . basename( $po_path ) . "\n";
echo "  Total in code:       {$total}\n";
echo "  Translated (ok):     {$covered} ({$pct}%)\n";
echo "  Missing from PO:     " . count( $missing ) . "\n";
echo "  In PO but empty:     " . count( $untranslated ) . "\n";
echo "  Garbled (?????):     " . count( $garbled ) . "\n";
echo "\n";

// ---- Missing from PO ----
if ( ! empty( $missing ) && ( $show_all || true ) ) { // always show missing
    echo "━━━ Strings MISSING from .po entirely ━━━\n\n";
    $idx = 0;
    foreach ( $missing as $msgid => $info ) {
        $idx++;
        $file = $info['file'];
        echo "  {$idx}. [{$file}]\n";
        echo "     msgid: {$msgid}\n";
        if ( $info['plural'] ) {
            echo "     plural: {$info['plural']}\n";
        }
        echo "\n";
    }
}

// ---- Untranslated ----
if ( ! empty( $untranslated ) ) {
    echo "━━━ Strings in .po but UNTRANSLATED (msgstr empty) ━━━\n\n";
    $idx = 0;
    foreach ( $untranslated as $msgid => $info ) {
        $idx++;
        $file = $info['file'];
        echo "  {$idx}. [{$file}]\n";
        echo "     msgid: {$msgid}\n";
        echo "\n";
    }
}

// ---- Garbled ----
if ( ! empty( $garbled ) ) {
    echo "━━━ Garbled translations (contains ?????) ━━━\n\n";
    $idx = 0;
    foreach ( $garbled as $msgid => $info ) {
        $idx++;
        $file = $info['file'];
        echo "  {$idx}. [{$file}]\n";
        echo "     msgid:   {$msgid}\n";
        echo "     current: " . $info['garbled_value'] . "\n";
        echo "\n";
    }
}

// ---- Translated (only with --show-all) ----
if ( $show_all && ! empty( $translated ) ) {
    echo "━━━ Successfully translated ━━━\n\n";
    $idx = 0;
    foreach ( $translated as $msgid => $info ) {
        $idx++;
        $file = $info['file'];
        echo "  {$idx}. [{$file}] {$msgid}\n";
    }
    echo "\n";
}

// ---- Final summary ----
echo "──────────────────────────────────────────────\n";
echo "  To update:  php tools/regenerate-po.php\n";
echo "  All done.\n";
