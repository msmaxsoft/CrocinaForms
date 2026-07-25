<?php
/**
 * Regenerate the Persian PO file with proper header and preserved translations.
 *
 * Usage: php tools/regenerate-po.php
 */

if ( 'cli' !== PHP_SAPI ) {
    die( 'CLI only.' );
}

define( 'PLUGIN_DIR', realpath( __DIR__ . '/..' ) );

$pot_path       = PLUGIN_DIR . '/languages/crocina-forms.pot';
$current_po_path = PLUGIN_DIR . '/languages/crocina-forms-fa_IR.po';

// --- Read POT ---
$pot = file_get_contents( $pot_path );
if ( false === $pot ) {
    die( "Failed to read POT file.\n" );
}

// --- Extract existing translations from current PO ---
$old_translations = array();
if ( is_file( $current_po_path ) ) {
    $old_po = file_get_contents( $current_po_path );
    // Match simple msgid/msgstr pairs
    preg_match_all( '/^msgid "([^"]*)"\nmsgstr "([^"]*)"/m', $old_po, $simple, PREG_SET_ORDER );
    foreach ( $simple as $m ) {
        $id  = stripcslashes( $m[1] );
        $str = stripcslashes( $m[2] );
        if ( '' !== $str ) {
            $old_translations[ $id ] = $str;
        }
    }
    // Match plural msgstr[0] entries
    preg_match_all( '/^msgid "([^"]*)"\nmsgstr\[0\] "([^"]*)"/m', $old_po, $plural, PREG_SET_ORDER );
    foreach ( $plural as $m ) {
        $id  = stripcslashes( $m[1] );
        $str = stripcslashes( $m[2] );
        if ( '' !== $str ) {
            $old_translations[ $id ] = $str;
        }
    }
}

// --- Build .po header ---
$now = gmdate( 'Y-m-d H:i:sO' );
$po  = '# Crocina Forms - Persian (Iran) Translation' . "\n";
$po .= '# Copyright (C) ' . gmdate( 'Y' ) . ' Mohammad Ghorbani' . "\n";
$po .= '# This file is distributed under the same license as the plugin.' . "\n";
$po .= '# https://github.com/mohammad/crocina-forms' . "\n";
$po .= 'msgid ""' . "\n";
$po .= 'msgstr ""' . "\n";
$po .= '"Project-Id-Version: Crocina Forms 0.1.0\\n"' . "\n";
$po .= '"POT-Creation-Date: ' . $now . '\\n"' . "\n";
$po .= '"PO-Revision-Date: ' . $now . '\\n"' . "\n";
$po .= '"Last-Translator: Mohammad Ghorbani\\n"' . "\n";
$po .= '"Language-Team: Persian\\n"' . "\n";
$po .= '"Language: fa_IR\\n"' . "\n";
$po .= '"MIME-Version: 1.0\\n"' . "\n";
$po .= '"Content-Type: text/plain; charset=UTF-8\\n"' . "\n";
$po .= '"Content-Transfer-Encoding: 8bit\\n"' . "\n";
$po .= '"Plural-Forms: nplurals=1; plural=0;\\n"' . "\n";
$po .= '"X-Generator: Crocina Forms POT Generator\\n"' . "\n";
$po .= "\n";

// --- Split POT into entries (split on lines starting with '#:') ---
$lines     = explode( "\n", $pot );
$entries   = array();
$current   = array();

$is_header = true;

foreach ( $lines as $line ) {
    if ( $is_header ) {
        if ( '' === trim( $line ) ) {
            $is_header = false;
        }
        continue;
    }

    if ( '' === trim( $line ) && ! empty( $current ) ) {
        $entries[] = $current;
        $current   = array();
        continue;
    }

    if ( '' !== trim( $line ) ) {
        $current[] = $line;
    }
}

if ( ! empty( $current ) ) {
    $entries[] = $current;
}

$preserved    = 0;
$new_strings  = 0;
$garbled_flagged = 0;

foreach ( $entries as $entry_lines ) {
    $entry_text = implode( "\n", $entry_lines ) . "\n";

    // Extract msgid from this entry
    $msgid = '';
    foreach ( $entry_lines as $l ) {
        if ( preg_match( '/^msgid "((?:[^"\\\\]|\\\\.)*)"/', $l, $m ) ) {
            $msgid = stripcslashes( $m[1] );
            break;
        }
    }

    if ( '' === $msgid ) {
        // Copy as-is (should not happen but be safe)
        $po .= $entry_text . "\n";
        continue;
    }

    // Check for existing translation
    $has_translation = isset( $old_translations[ $msgid ] );
    $translation     = $has_translation ? $old_translations[ $msgid ] : '';
    $is_garbled      = $has_translation && preg_match( '/[؟?]{3,}/', $translation );

    if ( $translation && ! $is_garbled ) {
        $preserved++;
        // Replace msgstr "" with actual translation
        $escaped = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $translation );
        $escaped = str_replace( array( "\n", "\r", "\t" ), array( '\\n', '\\r', '\\t' ), $escaped );

        // Replace the msgstr line(s)
        $new_entry = array();
        foreach ( $entry_lines as $l ) {
            if ( preg_match( '/^msgstr "/', $l ) ) {
                $new_entry[] = 'msgstr "' . $escaped . '"';
            } elseif ( preg_match( '/^msgstr\[\d+\] "/', $l ) ) {
                $new_entry[] = 'msgstr[0] "' . $escaped . '"';
                $new_entry[] = 'msgstr[1] "' . $escaped . '"';
            } else {
                $new_entry[] = $l;
            }
        }
        $po .= implode( "\n", $new_entry ) . "\n\n";
    } else {
        if ( $is_garbled ) {
            $garbled_flagged++;
            // Preserve the entry but with fuzzy flag and empty translation
            $po .= '#, fuzzy' . "\n";
        } else {
            $new_strings++;
        }
        $po .= $entry_text . "\n";
    }
}

// Write the file
$written = file_put_contents( $current_po_path, $po );
if ( false === $written ) {
    echo "Failed to write PO file.\n";
    exit( 1 );
}

echo "Regenerated PO file successfully.\n";
echo "  File size: " . number_format( $written ) . " bytes\n";
echo "  Total entries: " . count( $entries ) . "\n";
echo "  Translations preserved: $preserved\n";
echo "  New untranslated strings: $new_strings\n";
echo "  Garbled translations flagged: $garbled_flagged\n";

// Now compile MO
$cmd = 'msgfmt ' . escapeshellarg( $current_po_path ) . ' -o ' . escapeshellarg( PLUGIN_DIR . '/languages/crocina-forms-fa_IR.mo' );
exec( $cmd . ' 2>&1', $output, $exit_code );

if ( 0 === $exit_code ) {
    $mo_size = filesize( PLUGIN_DIR . '/languages/crocina-forms-fa_IR.mo' );
    echo "  MO compiled: " . number_format( $mo_size ) . " bytes\n";
} else {
    echo "  MO compilation output:\n";
    foreach ( $output as $line ) {
        echo "    $line\n";
    }
}
