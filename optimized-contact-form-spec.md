# Optimized Contact Form Plugin Spec

## Core Goal
Deliver a single-purpose, lightweight contact form plugin that avoids Gravity Forms' heavy metadata tables, does not enqueue bulky scripts or styles, and only uses WordPress built-in hooks/objects. The plugin should let an admin configure one or more contact forms in the dashboard and automatically send alerts with contextual info whenever a visitor submits a form.

## Data & Storage
- Do **not** create custom database tables beyond optionally logging submissions in a simple, indexed table with minimal columns (`form_id`, `submitted_at`, `field_snapshot` stored as JSON). Prefer transient-based caching for any temporary data.
- Keep persistent options limited to a single option entry (e.g., `optimized_contact_forms`) that stores every form definition, notification channel, and basic settings array.
- Store per-field metadata inline when the admin saves the form; avoid per-entry meta tables like `gf_entry_meta` by serializing only what is necessary for the notification payload.

## Admin UX
- Provide a stripped-down builder that lets an admin define:
  1. Form label/title
  2. Fields (label, slug/key, type, placeholder, required flag)
  3. Notification channels (email, Telegram, Eitaa, Bale, Rubika, WhatsApp via API, plus a fallback webhook)
  4. Toggle to log submissions (if enabled, use the lightweight table)
- Store forms as objects/arrays within the single options record; render the builder UI using native WP form markup (no third-party JS frameworks).
- Expose an inline preview/helper text to show what data is captured for each field.

## Front-End Rendering
- Provide a shortcode (`[opt-contact-form id="X"]`) that renders forms using a minimal HTML template with semantic markup. Avoid loading extra CSS/JS; rely on WordPress’s built-in `wp_enqueue_script( 'jquery' )` only if absolutely needed, otherwise render plain HTML and let the theme style it.
- Use nonces and `wp_verify_nonce` to secure submissions; sanitize and validate each field server-side.
- During submission, capture:
  - `get_the_title()` and `get_permalink()` of the current page (fallback to referer if not on a singular page)
  - `current_time( 'mysql' )` for timestamp
  - `GF_IP` equivalent via `$_SERVER['REMOTE_ADDR']` (support proxies via `HTTP_X_FORWARDED_FOR` if present)
  - Entire form contents serialized in a safe, human-readable text blob.

## Notification Engine
- Build a unified notification dispatcher that accepts a payload with the contextual metadata above plus each field’s label/value.
- Allow the admin to configure multiple endpoints per form while ensuring no duplicate alerts are sent for a single submission.
- Support the following channels:
  - **Email**: use `wp_mail()`; include HTML table summarizing fields and a plain-text fallback. Allow admin to define the “To”, “Subject” (with placeholders like `{form_title}`), and optional “From”.
  - **Telegram/Eitaa/Bale/Rubika**: use cURL or `wp_remote_post` to call the respective BOT/API URLs defined in settings. Provide placeholders to insert form data. For services without native WP SDKs, accept webhook URLs and bearer tokens in settings.
  - **WhatsApp (Business API)**: allow sending via a configurable `endpoint`, `token`, and template message; fall back to webhook if official API not available.
  - **Custom webhook**: allow admins to add generic HTTP endpoints.
- Ensure each channel gets a short descriptive message plus the link, time, page title, IP, and the field summary.
- Queue/schedule notifications via WP Cron only if the server load demands it; prefer immediate dispatch with lightweight `wp_remote_post()` for faster feedback.

## Logging & Monitoring
- If logging is enabled, insert one row into the light table per submission with only:
  - `form_id`, `submitted_at`, `user_ip`, `page_url`
  - `payload` column storing the `json_encode()` of sanitized field data.
- Provide an admin list table showing the last 50 submissions and quick links to resend or delete logs.

## Performance & Security Notes for AI
- Avoid loading admin scripts/styles globally; enqueue them only on the plugin’s own admin page.
- Use prepared statements or WPDB methods when interacting with the optional logging table.
- Use WP’s `current_user_can()` checks around admin routes to keep the surface minimal.
- Keep the plugin compatible with PHP 7.4+ and the earliest supported WordPress version without pulling in polyfills.
- Document each function with a short comment explaining intent (no need for docblocks everywhere).

## AI Implementation Guidelines
1. Start with a `class Optimized_Contact_Form` or similar core class that wires `init`, `admin_menu`, shortcode registration, and REST submission handling.
2. Build helper traits/services for `Form_Renderer`, `Submission_Handler`, and `Notification_Dispatcher`; keep them small (single responsibility).
3. Avoid heavyweight dependencies; rely on the WP core APIs (e.g., `wp_options`, `wp_mail`, `wp_ajax`, `wp_remote_post`).
4. Provide clear hooks/filters for later extensibility (`optimized_contact_form_before_send`, `optimized_contact_form_after_send`, etc.).
5. Deliver the plugin as a single directory with one main PHP file for bootstrap plus optional includes for admin, public, and notification code.

## Quick Notes
- Provide a CLI-style “dry run” mode for the notification dispatcher during development (e.g., log the payload instead of sending).
- Offer translation-ready strings (`__()`/`_e()`).
- Write automated unit tests or request them later to verify sanitization and notification formatting when a form is submitted.
