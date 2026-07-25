# 🚀 Crocina Forms v1.0 — Release Checklist

> **Target:** Production-ready release of the Crocina Forms plugin  
> **Status:** 🔲 Pre-release  
> **Date:** July 2026  
> **WP Compatibility:** 5.8 – 6.7  
> **PHP Compatibility:** 7.4 – 8.3

---

## 📋 How to use this checklist

Each item has a `[ ]` checkbox. Mark as:
- `[x]` — Verified and passing
- `[~]` — Verified with minor notes (see comments)
- `[ ]` — Not yet verified / needs attention

Use this file as a living document during the release process. Create a copy, check off items as you complete them, and attach the final version to the release notes.

---

## 1. 🔒 Security Hardening — Final Verification

> **Objective:** Confirm no security regression. Every AJAX endpoint, REST route, and form submission must be protected.

### 1.1 AJAX Handler Nonce Audit

| # | Handler | Nonce | Capability | Status |
|---|---------|-------|-----------|--------|
| 1 | `crocina_submit_form` | `crocina_form_ajax_{id}` | — (public) | `[ ]` |
| 2 | `crocina_get_nonce` | — (fresh nonce generation) | — (public) | `[ ]` |
| 3 | `crocina_get_eitaa_chat_id` | `crocina_get_eitaa_chat_id` | `manage_options` | `[ ]` |
| 4 | `crocina_test_eitaa` | `crocina_test_eitaa` | `manage_options` | `[ ]` |
| 5 | `crocina_watermark_preview` | `crocina_watermark_preview` | `manage_options` | `[ ]` |
| 6 | `crocina_batch_watermark` | `crocina_batch_watermark` | `manage_options` | `[ ]` |
| 7 | `crocina_clear_event_log` | `crocina_clear_event_log` | `manage_options` | `[ ]` |
| 8 | `crocina_export_form` | `crocina_export_form` | `manage_options` | `[ ]` |
| 9 | `crocina_import_form` | `crocina_import_single_form` | `manage_options` | `[ ]` |
| 10 | `crocina_test_notification` | `crocina_test_notification` | `edit_posts` | `[ ]` |
| 11 | `crocina_mark_read` | `crocina_mark_read` | `manage_options` | `[ ]` |
| 12 | `crocina_quick_view_log` | `crocina_quick_view_log` | `manage_options` | `[ ]` |
| 13 | `crocina_system_refresh` | `crocina_system_refresh` | `manage_options` | `[ ]` |

### 1.2 REST API Permission Audit

| # | Endpoint | Method | Permission | Status |
|---|----------|--------|-----------|--------|
| 1 | `/crocina/v1/forms` | GET | `manage_options` + nonce | `[ ]` |
| 2 | `/crocina/v1/forms/{id}` | GET | `manage_options` + nonce | `[ ]` |
| 3 | `/crocina/v1/forms/{id}/fields` | GET | `manage_options` + nonce | `[ ]` |
| 4 | `/crocina/v1/forms/{id}/reorder-fields` | POST | `manage_options` + nonce | `[ ]` |

### 1.3 Form Submission Security

| # | Check | Method | Status |
|---|-------|--------|--------|
| 1 | Nonce verification (AJAX + standard) | `wp_verify_nonce()` | `[ ]` |
| 2 | Honeypot field (randomized name) | `NONCE_SALT` + form_id | `[ ]` |
| 3 | Timestamp freshness (±1 hour window) | `absint($_POST['timestamp'])` | `[ ]` |
| 4 | Minimum time delay (2+ seconds) | `$min_delay` config | `[ ]` |
| 5 | Rate limiting (IP-based) | Transient with TTL | `[ ]` |
| 6 | Server-side `required` validation | `validate_field_value()` | `[ ]` |
| 7 | Format validation (email/url/number/tel) | `validate_field_value()` | `[ ]` |
| 8 | File upload: extension whitelist | `$allowed_extensions` | `[ ]` |
| 9 | File upload: MIME type check | `finfo_file()` | `[ ]` |
| 10 | File upload: path traversal guard | `wp_normalize_path()` + `strpos` | `[ ]` |
| 11 | File upload: size limit | `$max_attachment_size` | `[ ]` |
| 12 | Max fields limit (50) | `count($fields) > 50` | `[ ]` |

### 1.4 SSRF & Webhook Hardening

| # | Check | Implementation | Status |
|---|-------|---------------|--------|
| 1 | HTTPS-only endpoints | `is_allowed_endpoint()` | `[ ]` |
| 2 | Private IP block (DNS resolve) | `host_resolves_to_private_ip()` | `[ ]` |
| 3 | Domain allowlist | `crocina_allowed_webhook_domains` filter | `[ ]` |
| 4 | SSL verification (Eitaa) | `sslverify => true` | `[ ]` |
| 5 | Custom webhook `Authorization` header | Bearer token support | `[ ]` |
| 6 | Retry on failure (WP Cron, max 3) | 30s retry interval | `[ ]` |

### 1.5 Capability Mapping

| # | Capability | Used In | Status |
|---|-----------|---------|--------|
| 1 | `manage_options` | Settings, Export, Inbox actions, System Status | `[ ]` |
| 2 | `edit_posts` | Test notification | `[ ]` |
| 3 | `crocina_form` / `crocina_forms` | CPT capability type | `[ ]` |

---

## 2. 🧪 WordPress 6.7 Compatibility

> **Objective:** Ensure the plugin works correctly on WP 6.7. Test both fresh install and upgrade scenarios.

### 2.1 Fresh Install

| # | Test Case | Expected | Status |
|---|----------|---------|--------|
| 1 | Plugin activation | No errors, CPT registered | `[ ]` |
| 2 | Create new form | Meta boxes render, fields save | `[ ]` |
| 3 | Add 5+ field types | All types render in builder + frontend | `[ ]` |
| 4 | Save form with design settings | Design meta persists | `[ ]` |
| 5 | Insert `[crocina_form id="X"]` in post | Form renders on frontend | `[ ]` |
| 6 | Submit form (non-AJAX) | Redirect with success message | `[ ]` |
| 7 | Submit form (AJAX) | Inline success, no page reload | `[ ]` |
| 8 | View inbox | Log entries visible, pagination works | `[ ]` |
| 9 | Configure Telegram notification | Test button sends message | `[ ]` |
| 10 | Gutenberg block insertion | Block appears in inserter, renders preview | `[ ]` |

### 2.2 Upgrade Path (from prior version)

| # | Test Case | Expected | Status |
|---|----------|---------|--------|
| 1 | Deactivate old version, install new | No fatal errors | `[ ]` |
| 2 | DB migration runs (dbDelta) | Indexes upgraded, no SQL errors | `[ ]` |
| 3 | Legacy index cleanup | `cleanup_legacy_indexes()` runs | `[ ]` |
| 4 | Existing forms still render | Shortcode output unchanged | `[ ]` |
| 5 | Existing log entries searchable | FULLTEXT or LIKE fallback works | `[ ]` |
| 6 | `_load_textdomain_just_in_time` notice | No WP notice in debug.log | `[ ]` |
| 7 | Naming migration runs (underscore→hyphen) | `Crocina_Migration_Naming::run()` | `[ ]` |

### 2.3 Block Editor (Gutenberg) Compatibility

| # | Test Case | Expected | Status |
|---|----------|---------|--------|
| 1 | Insert Crocina Form block | Form list loads via REST API | `[ ]` |
| 2 | Select form from dropdown | Form preview renders | `[ ]` |
| 3 | Change template (card/minimal/bordered/shadow) | Preview updates + class changes | `[ ]` |
| 4 | Change theme (modern/classic/minimal) | Preview updates | `[ ]` |
| 5 | Set primary color | CSS variable applied to preview | `[ ]` |
| 6 | Switch viewport (mobile/tablet/desktop) | Preview width changes | `[ ]` |
| 7 | Toggle show_header / show_footer | Preview reflects toggle | `[ ]` |
| 8 | Save post with block | Frontend renders correctly | `[ ]` |
| 9 | Style Picker (registerBlockStyle) | Default style pre-selected | `[ ]` |
| 10 | Reset to defaults | All attributes reset | `[ ]` |

### 2.4 WP-CLI Command Verification

| # | Command | Expected Output | Status |
|---|---------|---------------|--------|
| 1 | `wp crocina cache flush --all` | Cache flushed | `[ ]` |
| 2 | `wp crocina cache warm` | All forms warmed | `[ ]` |
| 3 | `wp crocina form list --format=table` | Table of forms | `[ ]` |
| 4 | `wp crocina events tail --lines=10` | Last 10 log lines | `[ ]` |
| 5 | `wp crocina events export --format=csv` | CSV file generated | `[ ]` |
| 6 | `wp crocina events stats` | Event statistics | `[ ]` |
| 7 | `wp crocina security check` | Handler audit report | `[ ]` |
| 8 | `wp crocina db status` | Index + FULLTEXT status | `[ ]` |

---

## 3. 🗄️ Database Integrity

> **Objective:** Confirm schema is correct, indexes are in place, and no migration issues.

### 3.1 Schema Verification

| # | Check | Command / Method | Status |
|---|-------|-----------------|--------|
| 1 | Table `wp_crocina_form_logs` exists | `SHOW TABLES` | `[ ]` |
| 2 | Column `payload` is LONGTEXT | `SHOW COLUMNS` | `[ ]` |
| 3 | Column `payload_ft` exists (FULLTEXT fallback) | `SHOW COLUMNS` | `[ ]` |
| 4 | Index `form_submitted (form_id, submitted_at)` | `SHOW INDEXES` | `[ ]` |
| 5 | Index `form_read (form_id, is_read)` | `SHOW INDEXES` | `[ ]` |
| 6 | FULLTEXT `search_payload` on `payload` | `SHOW INDEXES` | `[ ]` |
| 7 | No legacy `form_id` standalone index | `SHOW INDEXES` | `[ ]` |
| 8 | No legacy `is_read` standalone index | `SHOW INDEXES` | `[ ]` |
| 9 | MariaDB < 10.6: FULLTEXT fallback active | `wp crocina db status` | `[ ]` |
| 10 | Migration log entry created | `get_option('crocina_migration_log')` | `[ ]` |

### 3.2 Data Integrity

| # | Test | Expected | Status |
|---|------|----------|--------|
| 1 | Create submission → log inserted | Row in `wp_crocina_form_logs` | `[ ]` |
| 2 | Log payload stores correctly (JSON) | `json_decode()` succeeds | `[ ]` |
| 3 | Data pruning cron scheduled | `wp_next_scheduled('crocina_forms_prune_logs')` | `[ ]` |
| 4 | Uninstall deletes all data | All options + table + posts removed | `[ ]` |

---

## 4. ⚡ Performance Benchmark

> **Objective:** Measure and verify key performance metrics. Run these tests on a clean WP 6.7 install.

### 4.1 Page Load Impact

| # | Metric | Target | Measured | Status |
|---|--------|--------|----------|-------|
| 1 | Page without form — DB queries | < 5 | `[ ]` queries | `[ ]` |
| 2 | Page without form — memory | < 5 MB | `[ ]` MB | `[ ]` |
| 3 | Page with 1 form — DB queries | < 15 | `[ ]` queries | `[ ]` |
| 4 | Page with 1 form — memory | < 10 MB | `[ ]` MB | `[ ]` |
| 5 | Page with 3 forms — DB queries | < 30 | `[ ]` queries | `[ ]` |
| 6 | Page with 3 forms — memory | < 15 MB | `[ ]` MB | `[ ]` |
| 7 | Form render time (single) | < 50 ms | `[ ]` ms | `[ ]` |
| 8 | Form render time (3 forms, batch loaded) | < 100 ms | `[ ]` ms | `[ ]` |

> **To measure:** Add `?crocina_benchmark=1` to any page with forms, or use Query Monitor plugin.

### 4.2 Cache Efficiency (System Status Tab)

| # | Metric | Target | Status |
|---|--------|--------|-------|
| 1 | Settings cache hit rate (after 2nd page load) | > 80% | `[ ]` |
| 2 | Design cache hit rate (batch-loaded) | > 90% | `[ ]` |
| 3 | Cache backend detection (Redis/Memcached) | Correctly detected | `[ ]` |
| 4 | Transient fallback active (no persistent cache) | Correctly detected | `[ ]` |

### 4.3 Asset Loading

| # | Asset | Loads On | Status |
|---|-------|---------|-------|
| 1 | `form.css` | Frontend pages WITH shortcode only | `[ ]` |
| 2 | `form.js` | Frontend pages WITH shortcode only | `[ ]` |
| 3 | `admin.css` | Admin crocina_form edit + settings pages only | `[ ]` |
| 4 | `admin.js` | Admin crocina_form edit + settings pages only | `[ ]` |
| 5 | `block.js` | Block editor (post/page) only | `[ ]` |
| 6 | `block.css` | Block editor (post/page) only | `[ ]` |

---

## 5. 🌐 i18n & L10n Completeness

> **Objective:** Ensure all user-facing strings are translatable and the Persian (fa_IR) translation is complete.

### 5.1 Translation Coverage

| # | Component | String Count | Status |
|---|-----------|-------------|--------|
| 1 | Frontend (form labels, messages) | Scan with `grep -r "__("` | `[ ]` |
| 2 | Admin settings page | All labels + help texts | `[ ]` |
| 3 | Admin form builder | Field labels, placeholders | `[ ]` |
| 4 | Inbox / list table | Column headers, bulk actions | `[ ]` |
| 5 | Export/Import | Messages, buttons | `[ ]` |
| 6 | Notifications | Channel labels, test results | `[ ]` |
| 7 | System Status tab | All section titles + labels | `[ ]` |
| 8 | WP-CLI output | Command descriptions | `[ ]` |
| 9 | Benchmark bar | Labels (time, queries, memory) | `[ ]` |
| 10 | JavaScript strings | `form.js`, `admin.js` (wp.i18n) | `[ ]` |

### 5.2 Persian (fa_IR) Verification

| # | Check | Expected | Status |
|---|-------|----------|-------|
| 1 | `.po` file exists for fa_IR | `languages/crocina-forms-fa_IR.po` | `[ ]` |
| 2 | `.mo` file compiled | `languages/crocina-forms-fa_IR.mo` | `[ ]` |
| 3 | Jalali date formatting works | Settings → short format | `[ ]` |
| 4 | RTL CSS loaded on Persian install | Admin + frontend | `[ ]` |
| 5 | Number formatting localized | `number_format_i18n()` used | `[ ]` |

### 5.3 Missing Strings Detection

```bash
# Scan PHP files for translatable strings
grep -rohP "(?<=__\(|_e\(|esc_html__\(|esc_html_e\(|esc_attr__\(|esc_attr_e\()['\"][^'\"]+['\"]" includes/ templates/ crocina-forms.php | sort -u > /tmp/php-strings.txt

# Compare with existing .po file
grep "^msgid " languages/crocina-forms-fa_IR.po | sed 's/^msgid "\(.*\)"$/\1/' | sort -u > /tmp/po-strings.txt

# Find missing strings
diff /tmp/po-strings.txt /tmp/php-strings.txt | grep "^>" > /tmp/missing-strings.txt
```

---

## 6. 🔄 Regression Testing

> **Objective:** Verify that existing features still work after all the refactoring and fixes.

| # | Test Case | Status |
|---|----------|-------|
| 1 | Create form with all 10 field types | `[ ]` |
| 2 | Drag-and-drop reorder fields | `[ ]` |
| 3 | Undo/Redo field changes | `[ ]` |
| 4 | Set required fields → server validation on submit | `[ ]` |
| 5 | Design: change button text/icon/color → preview updates | `[ ]` |
| 6 | Design: change theme (modern/classic/minimal) → preview updates | `[ ]` |
| 7 | Design: change template (card/minimal/bordered/shadow) → preview updates | `[ ]` |
| 8 | Design: set primary color → auto-palette generated | `[ ]` |
| 9 | Notification: enable Telegram + Bale + Email → all sent | `[ ]` |
| 10 | Notification: test button sends sample | `[ ]` |
| 11 | Inbox: filter by form, search, paginate | `[ ]` |
| 12 | Inbox: bulk delete, resend single | `[ ]` |
| 13 | Inbox: quick view modal renders payload | `[ ]` |
| 14 | Inbox: mark as read | `[ ]` |
| 15 | Settings: save all channels → data persists | `[ ]` |
| 16 | Settings: watermark preview renders | `[ ]` |
| 17 | Settings: batch watermark processes images | `[ ]` |
| 18 | Settings: System Status tab refresh button | `[ ]` |
| 19 | Export: single form JSON → copy/download | `[ ]` |
| 20 | Import: paste JSON → form updated | `[ ]` |
| 21 | File upload with watermark enabled | `[ ]` |
| 22 | Frontend: image preview before upload (FileReader) | `[ ]` |
| 23 | Frontend: drag-and-drop file upload | `[ ]` |
| 24 | Frontend: client-side file size validation | `[ ]` |
| 25 | Frontend: upload progress bar | `[ ]` |
| 26 | Frontend: non-AJAX fallback (JS disabled) | `[ ]` |

---

## 7. 📦 Release Artifacts

> **Objective:** Prepare everything needed for the release package.

| # | Artifact | Check | Status |
|---|---------|-------|-------|
| 1 | Plugin version bumped | `CROCINA_FORMS_VERSION` constant | `[ ]` |
| 2 | Stable tag updated | `readme.txt` / plugin header | `[ ]` |
| 3 | Changelog updated | `CHANGELOG.md` or readme.txt | `[ ]` |
| 4 | `.po` / `.mo` files regenerated | Latest strings extracted | `[ ]` |
| 5 | All JS files minified | `form.js`, `admin.js`, `block.js`, `editor.js` | `[ ]` |
| 6 | All CSS files minified | `form.css`, `admin.css` | `[ ]` |
| 7 | Assets version bumped | `CROCINA_FORMS_VERSION` for cache busting | `[ ]` |
| 8 | PHP syntax check on all files | `php -l` on every `.php` file | `[ ]` |
| 9 | JS syntax check on all files | `node -e "new Function(code)"` | `[ ]` |
| 10 | No debug/error_log in production code | `grep -r "error_log\|var_dump\|print_r\|console.log" excludes tests | `[ ]` |
| 11 | SVN/Git tag created | `v1.0.0` | `[ ]` |
| 12 | Plugin zip generated | `crocina-forms.zip` | `[ ]` |

---

## 8. 🧹 Final Cleanup — Known Gaps (for v1.0.1+)

> These items were identified during the audit as lower-priority improvements that can wait for the first patch release.

| # | Issue | Priority | Assigned to |
|---|-------|----------|------------|
| 1 | Cache `crocina_fields` like `crocina_form_design` | Medium | — |
| 2 | Connect `is_read` to Inbox UI (read/unread filter) | Medium | — |
| 3 | Refactor `Crocina_Render::render()` from static to instance | Low | — |
| 4 | Fix `_load_textdomain_just_in_time` for WP 6.7+ | Low | — |
| 5 | Add `aria-label` / `aria-describedby` to form fields | Low | — |
| 6 | Clean up `submitted_at` standalone legacy index | Low | — |
| 7 | CSS naming consistency (`crocina-` vs `crocina_`) | Low | — |

---

## ✅ Release Sign-off

| Role | Name | Date | Signature |
|------|------|------|-----------|
| Security Review | _________________ | ________ | ____________ |
| QA / Compatibility | _________________ | ________ | ____________ |
| Performance Review | _________________ | ________ | ____________ |
| i18n / L10n Review | _________________ | ________ | ____________ |
| **Final Approval** | _________________ | ________ | ____________ |

---

*Generated: July 2026 | Crocina Forms v1.0-rc.1*
