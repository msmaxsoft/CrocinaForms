$ErrorActionPreference = 'Stop'
$path = 'C:\laragon\www\GhitHub\CrocinaForms\includes\class-crocina-core.php'
$lines = [System.IO.File]::ReadAllLines($path)  # array of lines, no EOL

# --- Sanity checks ---
if ($lines[240].Trim() -ne '}') { throw "Line 241 not the expected closing brace, got: '$($lines[240])'" }

$method = @(
    '',
    "`t/**",
    "`t * Flush every plugin cache layer at once.",
    "`t *",
    "`t * Clears the global settings cache and drops ALL per-form design and",
    "`t * fields caches (both the in-memory request cache and the persistent",
    "`t * object cache group). Used after bulk operations such as saving global",
    "`t * settings via AJAX, where any form-scoped cache may now be stale.",
    "`t *",
    "`t * @return void",
    "`t */",
    "`tpublic function flush_cache() {",
    '',
    "`t`t// Global settings cache.",
    "`t`t`$this->flush_settings_cache();",
    '',
    "`t`t// Drop the entire in-memory request cache (design + fields entries).",
    "`t`t`$this->form_design_cache = array();",
    '',
    "`t`t// Flush the persistent object cache group so nothing stale survives.",
    "`t`tif ( function_exists( 'wp_cache_flush_group' ) ) {",
    "`t`t`twp_cache_flush_group( self::`$cache_group );",
    "`t`t} else {",
    "`t`t`twp_cache_flush();",
    "`t`t}",
    "`t}"
)

# Insert AFTER index 240 (line 241, the closing brace)
$before = $lines[0..240]
$after  = $lines[241..($lines.Length-1)]
$new = $before + $method + $after

# Write back with CRLF, preserving encoding (UTF-8 no BOM)
$enc = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($path, ($new -join "`r`n") + "`r`n", $enc)
Write-Output ("OK inserted flush_cache(). New line count: " + $new.Length)
