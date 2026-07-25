=== Crocina Forms ===
Contributors: mghorbani
Donate link: https://example.com
Tags: contact form, form builder, telegram notification, persian, jalali, gutenberg, webhook
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight, extensible contact form plugin with multi-channel notifications (Telegram, Bale, Eitaa, Rubika, WhatsApp), Jalali date support, and a modern Gutenberg block.

== Description ==

Crocina Forms is a purpose-built contact form plugin designed for speed, security, and flexibility. Unlike heavyweight form builders, it keeps a minimal database footprint while delivering powerful features:

= Key Features =

* **Visual Form Builder** — Drag-and-drop field editor with real-time preview. Supports 10 field types: text, textarea, email, telephone, number, URL, file upload, select, radio, and checkbox.
* **Gutenberg Block** — Native block with ServerSideRender, template styles (card, minimal, bordered, shadow), theme colors, responsive preview, and full InspectorControls integration.
* **Multi-Channel Notifications** — Send submissions to Telegram, Bale, Eitaa, Rubika, WhatsApp, email, and custom webhook endpoints — all at once or selectively per form.
* **Inbox & Logging** — Lightweight submission log with read/unread tracking, quick view, resend, bulk actions, and free-text search with FULLTEXT support.
* **Jalali (Persian/Solar) Calendar** — Full Jalali date formatting in inbox columns, submission notices, and dashboard stats. Supports short, full, Gregorian, and both modes.
* **AJAX Submissions** — Seamless no-reload form submission with file upload progress bar, client-side size validation, and drag-and-drop file preview.
* **Image Watermarking** — Optional GD/Imagick-based watermark for uploaded images, with live preview in settings. Supports text, logo, or combined watermarks.
* **3-Layer Caching** — In-memory → WP Object Cache → transient fallback for global settings, form designs, and form fields. Batch preloading and cache warmer included.
* **Import / Export** — Full JSON export/import of forms, settings, and inbox history for easy site migration and backups.
* **REST API** — `crocina/v1` endpoints for the Gutenberg block, form field reordering, and field details.
* **Event System** — PSR-14-style event dispatcher with log file, webhook forwarding, and WP-CLI tail command.
* **WP-CLI Integration** — 6+ CLI commands for cache management (`wp crocina cache`), event viewing (`wp crocina events`), form listing, webhook testing, and security auditing.
* **Security Hardened** — Server-side required-field validation, rate limiting, honeypot anti-spam, SSRF protection on all webhook endpoints, nonce rotating for cached pages, and capability checks on all admin routes.
* **Full i18n** — All strings translatable with `__()`/`_e()`. Persian (fa_IR) translation included with 227 translated strings.

= Supported Notification Channels =

* **Telegram** — Bot API via configurable endpoint
* **Bale** — bale.ai bot integration
* **Eitaa** — eitaayar.ir webhook integration
* **Rubika** — rubika.ir bot API
* **WhatsApp** — Configurable endpoint/token
* **Email** — HTML + plain-text via `wp_mail()` with file attachments
* **Custom Webhook** — JSON POST to arbitrary endpoints

= Placeholder System =

Each notification channel supports placeholders like `{form_title}`, `{page_title}`, `{fields}`, `{user_ip}`, `{submitted_at}` for contextual messaging.

== Installation ==

1. Upload the `crocina-forms` folder to the `/wp-content/plugins/` directory, or install directly from WordPress.org.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Crocina Forms** in the admin menu to create your first form.
4. Add fields, configure notification channels, and adjust design settings.
5. Place the form on any post or page using the Gutenberg block (`/Crocina Forms`) or the shortcode `[crocina_form id="1"]`.

= Requirements =

* WordPress 5.6 or higher (tested up to 6.7)
* PHP 7.4 or higher
* php-gd or Imagick extension (optional — required for image watermarking)
* Persian language users: the Jalali calendar works without any additional extensions

== Frequently Asked Questions ==

= How do I add a form to a post or page? =

You can use the Gutenberg block: search for "Crocina Forms" in the block inserter, select a form, and configure display options in the right sidebar. Alternatively, use the shortcode `[crocina_form id="X"]` in the Classic Editor or any widget area.

= Where are submissions stored? =

Submissions are stored in the `wp_crocina_form_logs` table — a lightweight, indexed table with columns for form_id, submitted_at, user_ip, page_url, and a JSON payload column. No per-entry meta tables. You can view, search, filter, resend, and delete submissions from the **Inbox** page.

= Which messaging platforms does the plugin support? =

Telegram, Bale, Eitaa, Rubika, and WhatsApp. Each requires a bot token and chat ID configured in **Settings → Notifications**. Per-form toggles let you control which channels are active for each individual form.

= Does the plugin work with caching plugins? =

Yes. The plugin uses rotating nonces via an AJAX endpoint (`crocina_get_nonce`) and a 3-layer caching system (in-memory → WP Object Cache → transient fallback) so form rendering and submission work reliably on cached pages.

= Is there a REST API? =

Yes. The plugin registers the `crocina/v1` namespace with endpoints for listing forms, retrieving form details and fields, and reordering fields. The Gutenberg block uses these endpoints for real-time data.

= Can I export and import forms? =

Yes. The **Export & Import** page lets you export all forms, settings, and inbox history as a single JSON file. You can also export individual forms directly from the edit screen. Import the JSON on another site to restore your configuration.

= Does the plugin support WP-CLI? =

Yes. Run `wp help crocina` to see available commands: `cache`, `events`, `form`, `webhook`, `security`, `warm-cache`, and `preload` — for cache management, event log viewing and export, form listing, and security auditing.

= Is the plugin translation-ready? =

Yes. All frontend and admin strings use `__()`, `_e()`, `_n()`, and `esc_attr__()` with the `crocina-forms` text domain. A Persian (fa_IR) translation is included with 227 strings already translated.

== Screenshots ==

1. Form builder — visual drag-and-drop editor with field cards
2. Gutenberg block — form selector with template and theme controls
3. Settings page — notification channel configuration
4. Inbox — submission log with read/unread filtering and quick view
5. Export & Import — full JSON backup and restore
6. Dashboard — form overview with daily submission chart
7. Watermark settings — image watermark preview and configuration
8. System Status — cache performance, database status, and environment info

== Changelog ==

= 0.1.0 =
* Initial release.
* Visual form builder with 10 field types, drag-and-drop reordering, and undo/redo.
* Gutenberg block with template styles (card, minimal, bordered, shadow), theme colors, and responsive preview.
* Multi-channel notifications: Telegram, Bale, Eitaa, Rubika, WhatsApp, Email, Webhook.
* Inbox with read/unread tracking, quick-view modal, mark-all-read, and bulk actions.
* Jalali (Persian) calendar support throughout admin and frontend.
* AJAX form submission with file upload preview and progress bar.
* Image watermarking with text/logo/combined modes and live preview.
* REST API (crocina/v1) for forms, fields, and reordering.
* Event system with file logging, webhook forwarding, and WP-CLI tail.
* 3-layer caching system with batch preloading and cache warmer.
* SSRF hardening, rate limiting, honeypot, and server-side field validation.
* Full JSON export/import for site migration and backups.
* WP-CLI integration: cache, events, form, webhook, security commands.
* 84+ PHPUnit tests covering AJAX security, admin pages, and cache warmer.
* Persian (fa_IR) translation with 227 strings.
* Tested with WordPress 6.7.

== Upgrade Notice ==

= 0.1.0 =
Initial release. No upgrade path from previous versions.

== Arbitrary Section ==

The plugin's architecture follows a service-container pattern with dependency injection. Key classes include:

* `Crocina_Forms_Core` — CPT registration, shortcode rendering, submission handling
* `Crocina_Admin` — Admin menus, screen options, meta boxes
* `Crocina_Ajax` — AJAX handlers for submissions, nonce rotation, watermark preview
* `Crocina_Notifications` — Dispatches notifications to all enabled channels
* `Crocina_Logger` — Submission logging with FULLTEXT search
* `Crocina_Event_Dispatcher` — PSR-14-style event system
* `Crocina_Rest_Controller` — REST API for Gutenberg block
* `Crocina_Channel_*` — Individual channel implementations (Telegram, Bale, etc.)

The event system dispatches `form.submitted`, `notification.sent`, `log.created`, and `crocina_form_after_submission` events. Subscribers can register via `Crocina_Subscriber_Interface`.

Filters:
* `crocina_notification_payload` — Modify the payload before sending
* `crocina_notification_payload_{channel}` — Channel-specific payload filtering
* `crocina_allowed_webhook_domains` — Customize allowed webhook endpoints
* `crocina_form_payload_before_dispatch` — Modify payload before dispatch
* `crocina_before_notification_dispatch` — Before notification dispatch
* `crocina_client_ip` — Override client IP detection
* `crocina_redirect_query_args` — Modify redirect URL parameters

Actions:
* `crocina_form_submitted` — After successful submission
* `crocina_form_after_submission` — After full processing
* `crocina_after_log_insert` — After a log entry is created
