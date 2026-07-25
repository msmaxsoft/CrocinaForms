حل ## Crocina Forms Overview

Crocina Forms is a lightweight custom form builder that registers a `crocina_form` custom post type, renders shortcodes on the frontend, logs submissions, and exposes admin screens for managing forms, inbox entries, and shared global settings.

### Core components
- **`Crocina_Forms_Core`** – Handles CPT registration, shortcode rendering, frontend scripts/styles, submission sanitization, logging, rate limiting, and notification dispatch. Key helpers:
  - `render_shortcode()` builds the frontend form UI along with nonce/honeypot protection.
  - `maybe_handle_submission()` validates POST data, builds the payload, dispatches notifications, and logs submissions via `Crocina_Logger`.
  - `is_logging_enabled()` determines whether a given form should persist entries, pulling the flag from `crocina_alert_options` (including backwards-compatible meta keys) and global settings.
  - `enqueue_frontend_assets()` adds the public `form.css`/`form.js` resources when Crocina forms are rendered.

- **`Crocina_Admin`** – Registers the admin menu, enqueues admin assets, defines meta boxes for form editing, handles screen options, and renders the Settings/Inbox/Export pages. Notable flows:
  - `enqueue_assets()` loads `assets/admin.css`/`admin.js` only on relevant screens and uses `enqueue_admin_style_once()` to avoid duplicate enqueue.
  - `render_fields_meta_box()` plus `assets/admin.js`/`admin.css` provide a drag-and-drop builder where each card defines a field’s label, slug, type, placeholder, options, and a new checkbox-based “Required” toggle.
  - `render_design_meta_box()` shows appearance controls (button text/icon, colors, extra CSS) and persists `crocina_form_design`.
  - `render_notifications_meta_box()` collects webhook/emails and per-form channel toggles; logging checkbox stores `enable_log` inside `crocina_alert_options`.
  - Settings page organizes notification channels (Telegram, Bale, Eitaa, Rubika, WhatsApp) and spam/logging controls with professional panel styling (`assets/admin.css`).
  - Inbox page uses `Crocina_Inbox_List_Table`, shows filter/search controls, handles resend actions, and respects screen option columns and pagination.

- **`Crocina_Inbox_List_Table`** – Extends `WP_List_Table` to list logs with columns (Date, Jalali, Form, Page, IP, Fields). It loads hidden column preferences via `resolve_hidden_columns()` and paginates via screen option `crocina_inbox_per_page`.
- **`Crocina_Logger`** – Creates `wp_crocina_form_logs`, records payloads, and retrieves/prunes logs for inbox operations.
- **`Crocina_Notifications`** – Sends email/webhook/instant-messaging notifications. It builds replacements from payloads and fires standard webhook/emails for each enabled channel.

### Key admin screens
- **Dashboard** (`Crocina_Forms` main menu) – Quick search, stats widget, and table of existing forms.
- **Settings** (`page=crocina-forms-settings`) – Panels for Telegram/email/webhook credentials plus spam prevention/log retention toggles; uses `crocina-settings-panel*` CSS.
- **Inbox** (`page=crocina-forms-inbox`) – `Crocina_Inbox_List_Table` with filter/search forms controls, screen-options-aware columns, row resend actions, and notices for resend results.
- **Export & Import** (`page=crocina-forms-export`) – JSON export of current configuration/history and form to import the payload.

### Hooks
- Actions: `crocina_form_submitted` after successful log + notification runs; `crocina_notification_payload` filters allow channel payload customization.
- Filters: `set-screen-option` for inbox pagination, `manage_edit-crocina_form_sortable_columns` and `handle_bulk_actions` for extending form list behavior.

Use this file as a reference when navigating the plugin’s entry points and admin flows.

---

## Known pitfalls and debugging notes

### Notifications & webhooks
- **Per-form Telegram toggle vs global settings**
  - Per-form toggle lives in `crocina_alert_options['enable_telegram']` (saved by `Crocina_Admin::sanitize_alert_options()`).
  - Dispatch condition in `Crocina_Notifications::dispatch()` additionally requires non-empty `telegram_token` و `telegram_chat` از `Crocina_Forms_Core::get_global_settings()`.
  - **اگر فرم فعال است ولی توکن/چت در تنظیمات سراسری خالی باشد، هیچ درخواستی ارسال نمی‌شود و فقط در صورت فعال بودن `WP_DEBUG` خطای کلی در لاگ می‌آید.**
  - When debugging “no Telegram notifications”, always check:
    - Form meta `enable_telegram` is `1`.
    - Global `telegram_token` و `telegram_chat` مقداردهی شده‌اند و endpoint صحیح است.

- **Bale / Eitaa / Rubika / WhatsApp channels**
  - Channel enable flags نیز در `crocina_alert_options` ذخیره می‌شوند (`enable_bale`, `enable_eitaa`, `enable_rubika`, `enable_whatsapp`).
  - `Crocina_Notifications::dispatch()` فقط خالی نبودن endpoint سراسری کانال را بررسی می‌کند؛ اگر `*_token` یا `*_chat` خالی باشند، درخواست بدون این مقادیر ارسال شده و معمولاً API با خطا برمی‌گردد.
  - پیام‌ها به صورت محافظه‌کارانه کوتاه می‌شوند (مثلاً محدودیت واتس‌اپ ۱۰۰۰ کاراکتر است) اما اگر فرمت بدنه یا الزامات API کانال رعایت نشود، تنها نشانه، پیام خطا در لاگ PHP (در حالت `WP_DEBUG`) است.

- **Endpoint allowlist و SSRF-hardening**
  - `Crocina_Notifications::is_allowed_endpoint()` فقط برای `telegram` و کانال‌های `bale` / `rubika` / `eitaa` یک allowlist ساده بر اساس host اعمال می‌کند و از فیلتر `crocina_allowed_webhook_domains` برای سفارشی‌سازی استفاده می‌کند.
  - کانال عمومی `webhook` و WhatsApp به صورت پیش‌فرض محدودیتی روی host ندارند (فقط `FILTER_VALIDATE_URL` اعمال می‌شود)، بنابراین:
    - تنها کاربران مدیر می‌توانند endpoint را تنظیم کنند؛ اما اگر قصد harden کردن دارید، از فیلتر `crocina_allowed_webhook_domains` استفاده کنید تا دامنه‌های مجاز را محدود یا کنترل کنید.
    - برای اضافه کردن دامنه سفارشی یا آزاد کردن کامل یک کانال، در قالب یا افزونه‌ی سفارشی از این فیلتر استفاده کنید.

- **Webhook payload و JSON encoding**
  - `Crocina_Notifications::send_webhook()` بدنه‌ی JSON را از ترکیب `message`, `form_id`, `fields`, `page_*`, `submitted_at*`, `user_ip`, و `placeholders` می‌سازد و سپس آن را از طریق دو فیلتر:
    - `crocina_notification_payload`
    - `crocina_notification_payload_{$channel}`
    قابل سفارشی‌سازی می‌کند.
  - اگر `wp_json_encode()` شکست بخورد، فقط لاگ ساده‌ی `Failed to encode JSON for {$channel}` ثبت می‌شود و هیچ درخواست HTTP ارسال نمی‌شود.
  - برای دیباگ عمیق‌تر، پیشنهاد می‌شود در محیط توسعه `log_error()` را گسترش دهید تا context بیشتری (مانند endpoint، form_id و نمونه‌ای از payload) را در لاگ ثبت کند.

### Core submission & rate limiting
- **عدم بازگشت (`return`) بعد از خطاها**
  - در `Crocina_Forms_Core::process_submission()`، اکثر مسیرهای خطا به `handle_submission_result()` دلیگیت می‌شوند.
  - این متد برای AJAX از `wp_send_json_*` استفاده می‌کند (که عملاً با `wp_die()` اسکریپت را متوقف می‌کند) و در حالت غیر AJAX با `wp_safe_redirect()` + `exit`، بنابراین **نبودن `return` بعد از این فراخوانی‌ها، در عمل منجر به ادامه‌ اجرای کد نمی‌شود.**
  - هنگام تغییر این متد، حواستان باشد که هر مسیری که به `handle_submission_result()` ختم می‌شود دیگر نباید ادامه‌ی پردازش داشته باشد.

- **Rate limiting بر اساس IP + User-Agent**
  - `Crocina_Forms_Core::is_rate_limited()` کلید ترنزینت را از `md5( $ip . '|' . $user_agent )` می‌سازد.
  - عوض کردن User-Agent می‌تواند عملاً یک «سطل» جدید بسازد و تعداد بیشتری ارسال در بازه‌ی زمانی مجاز شود؛ این یک انتخاب طراحی است، نه باگ امنیتی.
  - اگر خواستید سفت‌تر عمل کنید، می‌توانید با فیلتر یا فورک کردن کد، فقط IP را در کلید لحاظ کنید یا استراتژی پیشرفته‌تری برای fingerprint بسازید.

- **Timestamp و spam protection**
  - Timestamp در بازه‌ای نسبتاً باز (تا یک ساعت قبل/بعد) معتبر است تا مشکلات اختلاف ساعت را پوشش دهد؛ اگر سناریوهای امنیتی‌تری دارید، می‌توانید این بازه را کم کنید.
  - Honeypot نام را بر اساس `form_id` و `NONCE_SALT` می‌سازد، که در عمل برای ربات‌ها قابل پیش‌بینی نیست مگر اینکه به کانفیگ سرور دسترسی داشته باشند.

### Logging & attachments
- **ساخت payload برای لاگ‌ها**
  - `Crocina_Logger::log()` payload نهایی را از داده‌های ارسالی + اطلاعات محیطی (IP، user-agent، page_url، page_title) می‌سازد و در ستون `payload` به صورت JSON ذخیره می‌کند.
  - اگر اندازه‌ی JSON از ~۲۰۰KB بیشتر شود، `truncate_payload()` فقط مقادیر متنی فیلدها را کوتاه می‌کند تا ساختار قابل استفاده باقی بماند.
  - هنگام resend (چه از صفحه‌ی Inbox و چه از bulk action)، کد تلاش می‌کند از این payload، ساختار معقولی برای `fields` و متادیتا بسازد؛ اگر ساختار ذخیره شده غیرمعمول باشد، ممکن است فیلدها در پیام نهایی دقیقاً مطابق ارسال اصلی نباشند.

- **پیوست‌ها (attachments) در نوتیفیکیشن‌ها**
  - فایل‌ها در `Crocina_Forms_Core::process_submission()` با `wp_handle_upload()` آپلود و در payload در کلید `attachments` لیست می‌شوند (هر مورد شامل `name`, `url`, `path`).
  - `Crocina_Notifications::extract_attachment_paths()` فقط فایل‌هایی را به لیست ضمیمه ایمیل اضافه می‌کند که:
    - واقعاً روی دیسک وجود داشته باشند و قابل خواندن باشند؛
    - داخل دایرکتوری uploads وردپرس باشند؛
    - پسوند آن‌ها در لیست مجاز (`jpg`, `jpeg`, `png`, `pdf`, `gif`, `doc`, `docx`) باشد.
  - اگر پیوست‌ها در ایمیل نهایی دیده نمی‌شوند، اول مطمئن شوید که:
    - تنظیم سراسری `attachments_enabled` فعال است؛
    - `admin_email` در تنظیمات افزونه خالی نیست؛
    - پیوست‌ها در همان دایرکتوری uploads وردپرس ذخیره می‌شوند و پسوندشان بین پسوندهای مجاز است.

### Admin / Inbox behavior
- **Inbox resend (تکی و گروهی)**
  - دکمه‌ی resend تکی در `Crocina_Admin::handle_resend_log()` و bulk action `resend` در `Crocina_Inbox_List_Table::process_bulk_action()` هر دو payload را از JSON ذخیره شده بازسازی کرده و مجدداً `Crocina_Notifications::dispatch()` را صدا می‌زنند.
  - اگر ساختار payload قدیمی یا سفارشی باشد، ممکن است همه‌ی کلیدها (`submitted_at_jalali`, `page_title` و غیره) در نسخه‌ی بازسازی‌شده وجود نداشته باشند و پیام خروجی کمی متفاوت از ارسال اصلی شود.
  - برای دیباگ چنین مواردی، payload خام ذخیره شده در ستون `payload` جدول `crocina_form_logs` را مستقیماً بررسی کنید.

### Performance considerations
- **خواندن تنظیمات سراسری**
  - `Crocina_Forms_Core::get_global_settings()` در حال حاضر هر بار مستقیماً از `get_option()` می‌خواند و یک آرایه‌ی merge شده با defaults برمی‌گرداند.
  - برای اکثر سایت‌ها این هزینه ناچیز است، اما اگر خواستید در سایت‌های پرترافیک بهینه کنید، می‌توانید:
    - نتیجه را در یک پراپرتی استاتیک cache کنید؛ یا
    - از object cache/transient با TTL کوتاه استفاده کنید.

این بخش را هنگام توسعه ویژگی‌های جدید، دیباگ نوتیفیکیشن‌ها، و سخت‌تر کردن امنیت وبهوک‌ها به‌عنوان «نقشه‌ی مشکلات شناخته‌شده» در نظر بگیرید.

---

## گزارش اصلاحات امنیتی، بهینه‌سازی و سازگاری وردپرس (اعمال‌شده)

این بخش تغییراتی را که برای رفع مشکلات شناسایی‌شده اعمال شده‌اند مستند می‌کند.

### ۱) اعتبارسنجی SSL در اینتگریشن Eitaa
- در `includes/integrations/class-crocina-eitaa.php` مقدار `sslverify` از `false` به `true` تغییر کرد تا از حملات MITM هنگام ارسال توکن ربات و پیام‌ها جلوگیری شود.

### ۲) سخت‌سازی SSRF برای همه‌ی کانال‌ها (شامل `webhook` و `whatsapp`)
- در `Crocina_Notifications::is_allowed_endpoint()`:
  - فقط endpointهای `https` مجاز هستند (قابل تغییر با فیلتر `crocina_allow_http_webhooks`).
  - بررسی host خصوصی اکنون از متد جدید `host_resolves_to_private_ip()` استفاده می‌کند که رکوردهای DNS (A/AAAA) را resolve کرده و آدرس‌های خصوصی/رزروشده (RFC1918، loopback، link-local و ...) را با `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` رد می‌کند. اگر resolve DNS شکست بخورد، host به‌صورت ناامن در نظر گرفته می‌شود.
  - مقایسه‌ی allowlist اکنون case-insensitive است.
  - فیلترها: `crocina_disallow_private_webhook_hosts`, `crocina_allowed_webhook_domains`, `crocina_allow_http_webhooks`.

### ۳) اعتبارسنجی سمت‌سرور برای `required` و انواع فیلد
- در `Crocina_Forms_Core::process_submission()` برای همه‌ی فیلدهای غیرفایل:
  - فیلدهای علامت‌خورده به‌عنوان `required` در سمت سرور بررسی می‌شوند (نه فقط attribute HTML).
  - متد جدید `validate_field_value()` فرمت `email` (با `is_email`)، `url` (با `FILTER_VALIDATE_URL`)، `number` (با `is_numeric`) و `tel` (regex) را اعتبارسنجی می‌کند.
  - در صورت خطا، پیام مناسب از طریق `handle_submission_result()` بازگردانده می‌شود.

### ۴) رفع مشکل نانس در سایت‌های دارای کش صفحه
- endpoint جدید AJAX: `crocina_get_nonce` (و نسخه‌ی `nopriv`) در `Crocina_Ajax` که نانس‌های تازه (`_crocina_nonce` و `_crocina_ajax_nonce`) را برمی‌گرداند و از `nocache_headers()` استفاده می‌کند.
- در `assets/form.js`، پیش از ارسال AJAX، ابتدا نانس تازه دریافت و در فیلدهای مخفی جایگزین می‌شود؛ در صورت شکست، به نانس‌های چاپ‌شده fallback می‌شود.

### ۵) فایل `uninstall.php` و ایندکس مرکب دیتابیس
- فایل جدید `uninstall.php` تمام داده‌ها را هنگام حذف افزونه پاک می‌کند: آپشن‌ها (`crocina_forms_settings`, `crocina_logs_db_version`)، جدول `wp_crocina_form_logs`، پست‌های `crocina_form` و متاهایشان، ترنزینت‌های افزونه، و رویداد کرون `crocina_forms_prune_logs`. از multisite نیز پشتیبانی می‌کند.
- در `Crocina_Logger`:
  - ایندکس مرکب `KEY form_submitted (form_id, submitted_at)` به جدول اضافه شد.
  - `DB_VERSION` به `1.3` افزایش یافت تا `maybe_upgrade()` ایندکس جدید را از طریق `dbDelta` اعمال کند.

### ۶) جایگزینی توابع تاریخ منسوخ/غیراستاندارد
- تمام `current_time( 'timestamp' )` با `time() + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS )` جایگزین شد (در `class-crocina-core.php`، `class-crocina-logger.php`، `class-crocina-admin.php`).
- تمام فراخوانی‌های `date()` وابسته به timezone سرور با `gmdate()` جایگزین شدند (در `gregorian_to_jalali` helper، `prune()` و `get_form_stats()`).
- توجه: فراخوانی‌های `current_time( 'mysql' )` عمداً حفظ شده‌اند چون شکل توصیه‌شده‌ی وردپرس هستند، نه واریانت منسوخ `'timestamp'`.

هنگام توسعه‌ی بعدی، این اصلاحات را به‌عنوان رفتار پایه در نظر بگیرید (مثلاً وجود endpoint نانس، الزام HTTPS برای وبهوک‌ها، و اعتبارسنجی سمت‌سرور فیلدها).

