# Changelog — Crocina Forms

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] — 2026-07-24

> Initial production release. Complete architectural rewrite from legacy codebase with full security hardening, performance optimization, and modern WordPress standards.

### Added

#### 🧩 Gutenberg & Block Editor
- Full Gutenberg block with InspectorControls: template, theme, primary color, viewport, show header/footer, preview height
- WordPress Block Style Picker integration via `registerBlockStyle()` with `isActive` callback
- Style panel with tabbed Template/Theme selection and visual grids
- Responsive viewport switcher: mobile (375px), tablet (768px), desktop (full), auto-fit
- Primary color picker with auto-generated 5-shade CSS palette (50/100/200/300/400)
- Form fields panel: view, reorder, edit slugs, placeholder, helper text per field
- Hover tooltips on all block badges (template, height, theme, viewport)
- Reset to defaults button for all block attributes
- Block icon with proper registration for inserter panel

#### 🏗️ Form Builder (Admin)
- Drag-and-drop field builder with jQuery UI sortable/droppable/draggable
- Undo/Redo system with sessionStorage persistence (50-state stack)
- Quick-save button (disk icon) in builder header
- Visual template switcher buttons in builder header
- Template thumbnail selection modal with image previews
- Icon picker modal with search for Dashicons
- Color picker with hex display for all design controls
- Live theme/template preview with fade-in/out animation and label overlay
- Per-form template selector in design meta box
- Per-form theme selector in design meta box
- Default template/theme stored in post meta for shortcode fallback
- Compact responsive row/column layout for design controls
- Export/Import inline: single-form JSON export/copy/download and paste-import
- Test notification button with per-channel result display
- Notification channel guides: Telegram, Bale, Eitaa, Rubika, WhatsApp (step-by-step)

#### 🔒 Security
- Server-side required field validation (all field types)
- Server-side format validation: `is_email()` for email, `FILTER_VALIDATE_URL` for URL, `is_numeric()` for number, regex for tel
- SSRF protection with DNS resolution: `host_resolves_to_private_ip()` blocks RFC1918/loopback/link-local addresses
- HTTPS-only enforcement for all webhook endpoints (filterable via `crocina_allow_http_webhooks`)
- Domain allowlist via `crocina_allowed_webhook_domains` filter
- SSL verification enabled for Eitaa integration (`sslverify => true`)
- Custom Bearer Authorization header for webhooks
- Webhook retry on failure via WP Cron (max 3 retries, 30s interval)
- AJAX nonce refresh for cached pages (`crocina_get_nonce` endpoint with `nocache_headers()`)
- Watermark preview AJAX handler with `check_ajax_referer()`
- Batch watermark AJAX handler with `check_ajax_referer()`
- Form import AJAX handler with `check_ajax_referer()`
- Capability type set to `['crocina_form', 'crocina_forms']`
- HTTP request logging middleware (suspicious requests logged to events.log)
- Security audit CLI: `wp crocina security check` scans all AJAX handlers for nonce/capability
- File upload: MIME type verification via `finfo_file()`
- File upload: path traversal protection via `wp_normalize_path()` + `realpath()`
- File upload: extension + MIME + size + total size validation (3 layers)

#### 🗄️ Database & Schema
- Composite index `form_submitted (form_id, submitted_at)` for efficient log queries
- Composite index `form_read (form_id, is_read)` for unread count
- FULLTEXT index `search_payload` on payload column for fast inbox search
- FULLTEXT fallback for MariaDB < 10.6 (`payload_ft` column + cron-based sync)
- Legacy index cleanup: `cleanup_legacy_indexes()` removes standalone `form_id` and `is_read` indexes
- Migration log in `crocina_migration_log` option (timestamp + description per migration step)
- Naming convention migration (`Crocina_Migration_Naming`): renames `crocina_*` meta keys to `crocina-*`
- `submitted_at` standalone index cleanup in migration
- Complete `uninstall.php`: removes all options, posts, meta, custom table, transients, cron events
- DB status CLI: `wp crocina db status` shows index health, FULLTEXT compatibility, legacy warnings
- Index monitoring in System Status tab (current indexes, legacy remnants, FULLTEXT status)

#### ⚡ Performance & Caching
- 3-layer cache: in-memory → WP Object Cache → Transient fallback
- Settings cache: `get_global_settings()` with persistent + transient chain
- Form design cache: `get_form_design()` with batch preloading
- Batch loading: `preload_form_designs()` uses `update_meta_cache()` for all forms on a page
- Global settings preload: `preload_global_settings()` before any per-form shortcode processing
- Shortcode detection via `the_posts` for preload before render
- Cache warmer: `warm_cache()` builds object cache entries immediately after form save
- Cache warmer subscriber: pre-builds design + settings cache on `crocina_form_after_submission`
- Performance counters: settings cache hit rate, design cache efficiency (displayed in System Status)
- WP Object Cache detection: `uses_persistent_cache()` distinguishes Redis/Memcached from default
- Transient fallback with 1-hour TTL for non-persistent cache environments
- Cache flush CLI: `wp crocina cache flush --all` and `--design=123`
- Cache warm CLI: `wp crocina cache warm` pre-builds all active forms
- Benchmark tool: `?crocina_benchmark=1` appends render time, DB queries, and peak memory per form
- Conditional asset loading: CSS/JS only on pages with shortcode (`has_shortcode()` detection)
- JavaScript in footer: `wp_enqueue_script(..., true)` for non-render-blocking
- Progressive enhancement: forms work without JavaScript (non-AJAX fallback redirect)

#### 🎛️ Admin & Settings
- System Status tab: 8 sections (Overview, Cache Performance, Database Status, Capability Mapping, REST API Health, WP-CLI Commands, Error Log Guard, Environment) with real-time refresh
- Settings tabbed UI: standard `nav-tab-wrapper` + `tab-content`
- Watermark settings: type (text/logo/both), position, opacity, font size, color, logo upload, sample image upload
- Watermark live preview: real-time preview image with file-based caching (5s TTL)
- Batch watermark tool: chunked processing (5 images per AJAX call) with progress bar
- Event log viewer: admin debug page with last 100 log lines + Clear button
- Standard WordPress admin styling: `WP_List_Table`, `.postbox`, `.form-table`, `Settings API`

#### 🛠️ REST API
- `GET /crocina/v1/forms` — list all forms
- `GET /crocina/v1/forms/{id}` — single form with design meta
- `GET /crocina/v1/forms/{id}/fields` — fields only (lightweight)
- `POST /crocina/v1/forms/{id}/reorder-fields` — save field order
- Cache-Control headers: `no-cache` for forms list, `public, max-age=3600` for fields
- Permission callbacks on all endpoints (`manage_options` + nonce)

#### ⌨️ WP-CLI
- `wp crocina cache flush --all` — flush all plugin caches
- `wp crocina cache flush --design=123` — flush specific form design cache
- `wp crocina cache warm --form=123` — warm cache for a specific form
- `wp crocina cache warm` — warm cache for all active forms
- `wp crocina form list --format=table` — list forms with field and cache info
- `wp crocina events tail --lines=50` — tail events.log
- `wp crocina events tail --format=json` — JSON output for jq processing
- `wp crocina events export --format=csv --from=2024-01-01 --to=2024-12-31` — export event log
- `wp crocina events stats` — event statistics by type and hour
- `wp crocina events webhook-test` — test webhook endpoint
- `wp crocina db status` — database index and FULLTEXT health
- `wp crocina security check` — scan AJAX handlers for nonce/capability
- `wp crocina webhook test --url=... --form=5` — send test webhook payload

#### 🧪 Developer Tools
- Event dispatcher (PSR-14-like) with `Crocina_Event_Dispatcher` and `Crocina_Subscriber_Interface`
- Built-in events: `form.submitted`, `notification.sent`, `log.created`
- Event subscribers: CacheWarmer, DebugLogger, WebhookNotifier
- Event-based debug logging to `wp-content/uploads/crocina-forms/events.log`
- `Crocina_ServiceProvider` with factory methods for all services
- `Crocina_Logger_Interface` for swappable logger implementations
- `Crocina_Watermark` class with GD and Imagick support
- Migration system with version tracking (`DB_VERSION = 1.3`)
- Perf counters array accessible via `get_cache_stats()`
- Translation-ready: all strings with `__()` / `_e()` + Persian (fa_IR) `.po`/`.mo` files

#### 🎨 Frontend
- Theme system: modern, classic, minimal (CSS class + variable-driven)
- Template system: default, card, minimal, bordered, shadow (wrapper div + CSS)
- Primary color CSS variables with auto-generated palette
- `crocina-btn-primary-color` class for theme-aware button styling
- Image upload preview via FileReader API (before server upload)
- Drag-and-drop file upload zone
- Client-side file size validation (matches server max)
- Upload progress bar (XMLHttpRequest Level 2)

### Fixed

#### 🔓 Security Fixes
- **CRITICAL: CSRF** — `handle_watermark_preview()` lacked `check_ajax_referer()` — added
- **CRITICAL: CSRF** — `handle_batch_watermark()` lacked `check_ajax_referer()` — added
- **CRITICAL: CSRF** — `handle_import_form()` lacked `check_ajax_referer()` — added
- **HIGH: SSRF** — Webhook and WhatsApp channels had no domain restrictions — added `is_allowed_endpoint()` with DNS resolve
- **HIGH: SSL** — Eitaa integration had `sslverify => false` — changed to `true`
- **MEDIUM: Server validation** — Required fields only validated client-side — added server-side `validate_field_value()`
- **MEDIUM: Format validation** — No email/url/number/tel format check — added in `validate_field_value()`
- **LOW: Deprecated function** — `get_page_by_path()` used in `resolve_form()` — replaced with `get_posts()`
- **LOW: Deprecated function** — `current_time('timestamp')` used throughout — replaced with `gmdate()`
- **LOW: Prepared statement** — `SHOW COLUMNS FROM` and `SHOW INDEXES FROM` without `$wpdb->prepare()` — added

#### 🐛 Bug Fixes
- **Fatal: Non-static method** — `Crocina_Forms_Core::get_global_settings()` called statically in inbox table — fixed
- **Fatal: Undefined method** — `Crocina_Admin::render_design_meta_box()` called in builder-canvas.php after refactoring — fixed
- **Notice: textdomain** — `_load_textdomain_just_in_time` triggered by WP 6.7 early detection — added priority 1 on `init`
- **Builder: expand/collapse** — Card toggle `slideToggle()` and `toggleClass('is-open')` interfered — fixed
- **Builder: width class** — Width selector not applying `crocina-col-*` classes — fixed
- **Settings: duplicate design controls** — Design meta box rendered twice — deduplicated
- **Settings: dead CSS** — 60+ `.crocina-settings-*` selectors left in admin.css after template rewrite — removed
- **Settings: broken CSS group** — Removing `.crocina-settings-panels` from group selector broke `.post-php` meta box styling — restored
- **Inbox: dead CSS** — ~45 `.crocina-inbox-*` selectors left after standardisation — removed
- **Watermark: Farsi text reversed** — Persian text rendered inverted — fixed text direction handling
- **Watermark: broken URL** — Watermark documentation link pointed to wrong resource — fixed
- **JS: system refresh syntax** — Unbalanced brackets in admin.js refresh handler — fixed `loaderIdx++; } ); }` structural issue
- **JS: system refresh selectors** — `eq()` indices mismatched after template restructure — updated selectors
- **REST health: always 'registered'** — `rest_do_request()` returns truthy even for 404 — replaced with proper route registration checking via `rest_get_server()->get_routes()`
- **System refresh: missing env update** — Environment section not updated on AJAX refresh — added `data-env-key` attributes + JS update loop
- **Benchmark: Dashicons dependency** — Benchmark bar used `<span class="dashicons">` which may not load on frontend — replaced with Unicode symbols
- **Benchmark: Unicode in single quotes** — PHP `\u{...}` escapes don't work in single-quoted strings — replaced with UTF-8 hex escapes

### Improved

#### 🏗️ Architecture
- **Monolithic Admin → 3 classes:** `Crocina_Admin_Meta_Boxes`, `Crocina_Admin_Page_Renderer`, `Crocina_Ajax` — each with single responsibility
- **Static → DI:** All static methods removed; constructor injection via `Crocina_App` container
- **ServiceProvider:** `Crocina_ServiceProvider` registered with factory methods for all services
- **Event system:** Action hooks replaced with clean PSR-14-like abstraction
- **Logger interface:** `Crocina_Logger_Interface` for swappable implementations
- **Channel interface:** Telegram, Bale, Eitaa, Rubika, WhatsApp — each a separate class with `send($message, $config)`
- **PSR-4 autoloading:** `spl_autoload_register` with `crocina-` prefix fallback
- **Migration system:** Versioned schema upgrades with `dbDelta()` + migration log
- **Naming convention:** Migration from `crocina_*` (underscore) to `crocina-*` (hyphen) for consistency

#### 🗄️ Database Performance
- **LIKE → FULLTEXT:** Inbox search upgraded from `LIKE '%term%'` (full table scan) to `MATCH(payload) AGAINST('term')`
- **Composite indexes:** Replaced standalone `form_id` and `submitted_at` indexes with `form_submitted (form_id, submitted_at)` — eliminates redundant index
- **COUNT optimization:** `get_logs_count()` and `get_unread_count()` use indexed columns
- **MariaDB compatibility:** FULLTEXT on LONGTEXT not supported in MariaDB < 10.6 — added `payload_ft` VARCHAR column + sync cron

#### ⚡ Performance
- **3-layer cache:** Reduced `get_option()` calls from N per request to 1 (shared across all forms on page)
- **Batch preloading:** Form designs loaded in a single `update_meta_cache()` call instead of N `get_post_meta()` calls
- **Cache warmer:** First visitor after form save no longer hits cold cache
- **Conditional assets:** CSS/JS enqueued only on pages with `has_shortcode()` — saves HTTP requests on non-form pages
- **Progressive enhancement:** Forms fully functional without JavaScript (non-AJAX fallback)
- **Nonce caching:** AJAX nonce refresh eliminates cache invalidation on cached pages
- **Object cache detection:** Automatically uses persistent cache (Redis/Memcached) when available, falls back to transients

#### 🎨 Admin UI/UX
- **Standard WP styling:** All admin pages converted to `.postbox`, `.form-table`, `.nav-tab-wrapper`, `WP_List_Table` — consistent with WordPress core
- **Design controls:** Compact row/column responsive layout (replaces full-width vertical stack)
- **Builder UX:** Undo/Redo, quick-save, visual template switcher, icon picker, live preview
- **Notification guides:** Restored comprehensive step-by-step guides for all channels
- **Loading states:** All AJAX buttons disabled during requests with visual feedback
- **Progress bars:** File upload progress, batch watermark progress
- **Responsive admin:** All pages functional on mobile viewports

#### 🔧 Developer Experience
- **WP-CLI:** 13+ commands for cache management, event monitoring, form listing, security audit, database status
- **REST API:** 4 endpoints for programmatic form management
- **Performance monitoring:** Real-time cache stats in System Status tab
- **Benchmark tool:** Quick performance measurement via URL parameter
- **Event log:** Structured debugging with subscriber-based logging
- **Translation ready:** POT-compatible with complete Persian translation
- **Migration log:** Full history of schema and naming migrations
- **Security audit:** Automated CLI scanning for nonce/capability gaps

### Security

- [SEC-01] **CSRF protection** — All 13 AJAX handlers now verify nonce via `check_ajax_referer()`
- [SEC-02] **SSRF hardening** — All webhook channels validate endpoints: HTTPS-only, DNS-resolved private IP block, domain allowlist
- [SEC-03] **SSL verification** — Eitaa, Bale, Rubika API calls verify SSL certificates
- [SEC-04] **Server-side validation** — Required fields, email/URL/number/tel format validated server-side
- [SEC-05] **File upload** — 3-layer validation: extension whitelist, MIME type (finfo), path traversal (realpath check)
- [SEC-06] **Rate limiting** — IP-based rate limiting with configurable window and threshold
- [SEC-07] **Honeypot** — Randomized field name based on `NONCE_SALT` + `form_id`
- [SEC-08] **Capability checks** — `manage_options` on all admin endpoints; REST API with permission callbacks
- [SEC-09] **Error exposure** — `error_log()` calls guarded by `defined('WP_DEBUG') && WP_DEBUG`
- [SEC-10] **Data cleanup** — Complete uninstall removes all plugin data (options, table, posts, transients, crons)
- [SEC-11] **Nonce refresh** — Fresh nonces for cached pages via AJAX endpoint
- [SEC-12] **Webhook auth** — Support for Bearer Authorization header on webhook requests
- [SEC-13] **Webhook retry** — Automatic retry with backoff (30s, max 3) on 5xx/timeout
- [SEC-14] **RBAC** — Custom `crocina_form`/`crocina_forms` capability type for granular access control

---

## [0.9.0] — 2026-06-XX

> Legacy release prior to architectural rewrite.

- Basic form builder with drag-and-drop fields
- `[crocina_form id="X"]` shortcode
- Frontend form rendering with CSS/JS
- Non-AJAX and AJAX form submission
- Honeypot spam protection
- Rate limiting
- Notification channels: Telegram, Bale, Eitaa, Rubika, WhatsApp
- Email notifications via `wp_mail()`
- Webhook integration
- Inbox with `WP_List_Table`
- Settings page with notification channel configuration
- Export/Import page
- Jalali date support
- Persian (fa_IR) translation
