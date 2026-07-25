<?php
defined( 'ABSPATH' ) || exit;

$data = is_array( $data ?? null ) ? $data : array();
$settings = $data['settings'] ?? array();
// Preview vars removed — notification preview section was deleted.
$db_status = $data['db_status'] ?? null;
$stats = $data['cache_stats'] ?? array();

/* Helper: render a text/number input row */
$text = function( $key, $label, $type = 'text', $extra = '' ) use ( $settings ) {
	$val = $settings[ $key ] ?? '';
	$class = 'regular-text';
	if ( 'number' === $type ) {
		$class = 'small-text';
	}
	?>
	<tr>
		<th scope="row"><?php echo esc_html( $label ); ?></th>
		<td>
			<input
				type="<?php echo esc_attr( $type ); ?>"
				name="crocina_forms_settings[<?php echo esc_attr( $key ); ?>]"
				value="<?php echo esc_attr( $val ); ?>"
				class="<?php echo esc_attr( $class ); ?>"
				<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			/>
		</td>
	</tr>
	<?php
};

/* Helper: render a checkbox row */
$checkbox = function( $key, $label ) use ( $settings ) {
	?>
	<tr>
		<th scope="row"></th>
		<td>
			<label>
				<input type="hidden" name="crocina_forms_settings[<?php echo esc_attr( $key ); ?>]" value="0" />
				<input
					type="checkbox"
					name="crocina_forms_settings[<?php echo esc_attr( $key ); ?>]"
					value="1"
					<?php checked( $settings[ $key ] ?? 0, 1 ); ?>
				/>
				<?php echo esc_html( $label ); ?>
			</label>
		</td>
	</tr>
	<?php
};

/* Helper: render a select row */
$select = function( $key, $label, $options ) use ( $settings ) {
	$val = $settings[ $key ] ?? '';
	?>
	<tr>
		<th scope="row"><?php echo esc_html( $label ); ?></th>
		<td>
			<select name="crocina_forms_settings[<?php echo esc_attr( $key ); ?>]">
				<?php foreach ( $options as $opt_val => $opt_label ) : ?>
					<option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $val, $opt_val ); ?>>
						<?php echo esc_html( $opt_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
	<?php
};

/* Helper: render a sticky save button bar for each tab */
$save_bar = function() {
	?>
	<div class="crocina-save-bar">
		<button type="submit" class="button button-primary crocina-save-settings">
			<span class="dashicons dashicons-yes-alt" style="font-size:18px;width:18px;height:18px;vertical-align:middle;margin:-2px 4px 0 0;"></span>
			<?php esc_html_e( 'Save Settings', 'crocina-forms' ); ?>
		</button>
		<span class="crocina-save-feedback" aria-live="polite" style="margin-left:0.5rem;font-size:13px;"></span>
	</div>
	<?php
};


/* Helper: render a collapsible guide section */
$guide = function( $summary, $content_callback ) {
	?>
	<details style="margin-bottom:0.5rem;">
		<summary style="cursor:pointer;color:#2271b1;font-weight:600;"><?php echo esc_html( $summary ); ?></summary>
		<div style="padding:0.65rem 0.75rem;background:#f0f6fc;border:1px solid #c5d9ed;border-left:4px solid #2271b1;border-radius:4px;margin-top:0.35rem;font-size:13px;line-height:1.7;">
			<?php $content_callback(); ?>
		</div>
	</details>
	<?php
};
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Crocina Settings', 'crocina-forms' ); ?></h1>
	<hr class="wp-header-end" />
	<?php settings_errors( 'crocina_forms_settings' ); ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'crocina_forms_settings' ); ?>

		<nav class="nav-tab-wrapper">
			<a href="#tab-general" class="nav-tab nav-tab-active"><?php esc_html_e( 'General', 'crocina-forms' ); ?></a>
			<a href="#tab-notifications" class="nav-tab"><?php esc_html_e( 'Notifications', 'crocina-forms' ); ?></a>
			<a href="#tab-security" class="nav-tab"><?php esc_html_e( 'Security', 'crocina-forms' ); ?></a>
			<a href="#tab-attachments" class="nav-tab"><?php esc_html_e( 'Attachments', 'crocina-forms' ); ?></a>
			<a href="#tab-watermark" class="nav-tab"><?php esc_html_e( 'Watermark', 'crocina-forms' ); ?></a>
			<a href="#tab-system" class="nav-tab"><?php esc_html_e( 'System', 'crocina-forms' ); ?></a>
		</nav>

		<div class="tab-content" id="tab-general" style="margin-top:1rem;">
			<table class="form-table" role="presentation">
				<tbody>
					<?php $select( 'jalali_date_format', __( 'Jalali date display', 'crocina-forms' ), array(
						'full'  => __( 'Full (date + time)', 'crocina-forms' ),
						'short' => __( 'Short (month/day)', 'crocina-forms' ),
					) ); ?>
					<?php $text( 'dashboard_stats_days', __( 'Dashboard stats days', 'crocina-forms' ), 'number', 'min="1" max="90"' ); ?>
					<?php $checkbox( 'auto_append_jalali', __( 'Auto-append Jalali date to custom messages', 'crocina-forms' ) ); ?>
					<?php $select( 'auto_append_datetime_mode', __( 'Auto-append date type', 'crocina-forms' ), array(
						'jalali'    => __( 'Jalali only', 'crocina-forms' ),
						'gregorian' => __( 'Gregorian only', 'crocina-forms' ),
						'both'      => __( 'Both Jalali and Gregorian', 'crocina-forms' ),
					) ); ?>
				</tbody>
			</table>



			<h2><?php esc_html_e( 'Logging', 'crocina-forms' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<?php $checkbox( 'logging_enabled', __( 'Enable logging for all submissions', 'crocina-forms' ) ); ?>
					<?php $text( 'log_retention_days', __( 'Log retention (days)', 'crocina-forms' ), 'number', 'min="1"' ); ?>
					<?php $checkbox( 'event_logging_enabled', __( 'Enable event debug logging', 'crocina-forms' ) ); ?>
				</tbody>
			</table>
			<p class="description" style="margin-top:-0.5rem;">
				<?php esc_html_e( 'Event logs are written to', 'crocina-forms' ); ?>
				<code>wp-content/uploads/crocina-forms/events.log</code>.
				<?php esc_html_e( 'Useful for debugging without enabling WP_DEBUG.', 'crocina-forms' ); ?>
			</p>
			<?php $save_bar(); ?>
		</div>

		<div class="tab-content" id="tab-notifications" style="display:none;margin-top:1rem;">
			<h2><?php esc_html_e( 'Admin Email', 'crocina-forms' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<?php $text( 'admin_email', __( 'Email recipients', 'crocina-forms' ), 'text', 'class="regular-text" placeholder="admin@example.com"' ); ?>
				</tbody>
			</table>

			<!-- ========== Telegram ========== -->
			<h2><?php esc_html_e( 'Telegram', 'crocina-forms' ); ?></h2>
			<?php $guide( 'راهنمای کامل و گام‌به‌گام تنظیم تلگرام (کلیک کنید)', function() { ?>
				<p><strong>مرحله ۱: ساخت ربات تلگرام و دریافت توکن</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;">
					<li>اپلیکیشن <strong>تلگرام</strong> را باز کنید.</li>
					<li>در بخش جستجو، کاربر <strong>@BotFather</strong> را پیدا کنید. <a href="https://t.me/BotFather" target="_blank" rel="noreferrer noopener">(لینک مستقیم)</a></li>
					<li>دکمه <strong>Start</strong> یا <strong>/start</strong> را بزنید.</li>
					<li>دستور <strong>/newbot</strong> را ارسال کنید.</li>
					<li>یک <strong>نام</strong> برای ربات خود وارد کنید (مثلاً «ربات فرم‌های من»).</li>
					<li>یک <strong>نام کاربری (username)</strong> برای ربات وارد کنید که باید به <code>bot</code> ختم شود (مثلاً <code>MyFormBot</code>Bot@).</li>
					<li>پس از ساخت موفق، @BotFather یک <strong>توکن (Token)</strong> به شما می‌دهد. آن را کپی کنید.</li>
					<li>توکن کپی‌شده را در فیلد <strong>«Telegram token»</strong> در همین صفحه قرار دهید.</li>
				</ol>

				<p><strong>مرحله ۲: دریافت شناسه چت (Chat ID)</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;">
					<li>در تلگرام، کاربر <strong>@RawDataBot</strong> را جستجو کنید. <a href="https://t.me/RawDataBot" target="_blank" rel="noreferrer noopener">(لینک مستقیم)</a></li>
					<li>دکمه <strong>Start</strong> را بزنید.</li>
					<li>ربات یک پیام شامل اطلاعات حساب شما ارسال می‌کند.</li>
					<li>به دنبال عبارت <code>"id":</code> در پیام بگردید. عدد جلوی آن <strong>Chat ID</strong> شماست (مثلاً <code>123456789</code>).</li>
					<li>اگر می‌خواهید از یک <strong>کانال یا گروه</strong> استفاده کنید:
						<ul style="margin:0.25rem 0 0.5rem;padding-inline-start:1.2rem;">
							<li>ربات خود را به عنوان <strong>مدیر (admin)</strong> به کانال/گروه اضافه کنید.</li>
							<li>یک پیام در کانال/گروه ارسال کنید.</li>
							<li>به <strong>https://api.telegram.org/bot<code>TOKEN</code>/getUpdates</strong> بروید (به جای <code>TOKEN</code> توکن خود را قرار دهید).</li>
							<li>در پاسخ JSON، به دنبال <code>"chat":{"id":-123456789}</code> بگردید — این Chat ID کانال/گروه شماست.</li>
						</ul>
					</li>
					<li>Chat ID را در فیلد <strong>«Telegram chat ID»</strong> قرار دهید.</li>
				</ol>

				<p><strong>مرحله ۳: تأیید تنظیمات</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;">
					<li>فیلد <strong>«Telegram endpoint»</strong> معمولاً نیازی به تغییر ندارد و به صورت پیش‌فرض از <code>https://api.telegram.org</code> استفاده می‌کند.</li>
					<li>تنظیمات را ذخیره کنید.</li>
					<li>در صفحه ویرایش هر فرم، بخش Notifications را باز کرده و گزینه <strong>«Use the global Telegram settings for this form»</strong> را فعال کنید.</li>
					<li>برای تست، از دکمه <strong>«Send test notification»</strong> در همان بخش استفاده کنید.</li>
				</ol>
				<p style="margin-top:0.5rem;color:#5a6577;font-size:12px;">
					<strong>نکته:</strong> توکن ربات باید مخفی بماند — هر کسی که به توکن دسترسی داشته باشد می‌تواند از طرف ربات شما پیام ارسال کند.
				</p>
			<?php } ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<?php $text( 'telegram_token', __( 'Telegram token', 'crocina-forms' ), 'text', 'placeholder="1234567890:ABCdefGHIjklMNOpqrsTUVwxyz" class="regular-text"' ); ?>
					<?php $text( 'telegram_chat', __( 'Telegram chat ID', 'crocina-forms' ), 'text', 'placeholder="123456789 یا -987654321" class="regular-text"' ); ?>
					<?php $text( 'telegram_endpoint', __( 'Telegram endpoint', 'crocina-forms' ), 'url', 'class="regular-text" placeholder="https://api.telegram.org"' ); ?>
				</tbody>
			</table>
			<p>
				<button type="button" class="button button-primary crocina-test-channel-btn" data-channel="telegram" data-nonce="<?php echo esc_attr( wp_create_nonce( 'crocina_test_channel' ) ); ?>"><?php esc_html_e( 'Send Test Message', 'crocina-forms' ); ?></button>
			</p>
			<div class="crocina-channel-test-result" data-channel="telegram" role="status" aria-live="polite"></div>

			<!-- ========== Bale ========== -->
			<h2><?php esc_html_e( 'Bale', 'crocina-forms' ); ?></h2>
			<?php $guide( 'راهنمای کامل و گام‌به‌گام تنظیم بله (کلیک کنید)', function() { ?>
				<p><strong>مرحله ۱: ساخت ربات بله و دریافت توکن</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;">
					<li>اپلیکیشن <strong>بله (Bale)</strong> را باز کنید.</li>
					<li>در بخش جستجو، کاربر <strong>@BotFather</strong> را پیدا کنید. <a href="https://ble.ir/botfather" target="_blank" rel="noreferrer noopener">(لینک مستقیم)</a></li>
					<li>دستور <strong>/start</strong> را ارسال کنید.</li>
					<li>دستور <strong>/newbot</strong> را ارسال کنید.</li>
					<li>یک <strong>نام</strong> برای ربات خود وارد کنید.</li>
					<li>یک <strong>نام کاربری (username)</strong> وارد کنید (باید به <code>bot</code> ختم شود).</li>
					<li>@BotFather یک <strong>توکن (Token)</strong> به شما می‌دهد — آن را کپی کرده و در فیلد <strong>«Bale token»</strong> قرار دهید.</li>
				</ol>

				<p><strong>مرحله ۲: دریافت شناسه چت (Chat ID)</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;">
					<li>در بله، کاربر <strong>@infoBot</strong> را جستجو کنید.</li>
					<li>دستور <strong>/start</strong> را ارسال کنید.</li>
					<li>ربات اطلاعات حساب شما شامل <strong>شناسه کاربری</strong> را نمایش می‌دهد.</li>
					<li>اگر می‌خواهید ربات به یک <strong>کانال</strong> پیام ارسال کند:
						<ul style="margin:0.25rem 0 0.5rem;padding-inline-start:1.2rem;">
							<li>ربات خود را به عنوان مدیر به کانال اضافه کنید.</li>
							<li>یک پیام آزمایشی در کانال ارسال کنید.</li>
							<li>آدرس زیر را در مرورگر باز کنید (به جای <code>TOKEN</code>):<br/>
							<code>https://tapi.bale.ai/botTOKEN/getUpdates</code></li>
							<li>در پاسخ، به دنبال <code>"chat":{"id":</code> بگردید — مقدار بعد از آن Chat ID کانال شماست.</li>
						</ul>
					</li>
					<li>Chat ID را در فیلد <strong>«Bale chat ID»</strong> قرار دهید.</li>
				</ol>

				<p><strong>مرحله ۳: تنظیم endpoint</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;">
					<li>فیلد <strong>«Bale endpoint»</strong>: آدرس <code>https://tapi.bale.ai</code> را وارد کنید (این آدرس پیش‌فرض است).</li>
					<li>توجه: API بله با Telegram سازگار است اما از آدرس متفاوتی استفاده می‌کند — <code>https://tapi.bale.ai</code>.</li>
					<li>تنظیمات را ذخیره کنید.</li>
					<li>در صفحه ویرایش فرم، بخش Notifications را باز کرده و <strong>«Send payload to Bale endpoint/token»</strong> را فعال کنید.</li>
				</ol>
				<p style="margin-top:0.5rem;color:#5a6577;font-size:12px;">
					<strong>نکته مهم:</strong> برخلاف Telegram، API بله پارامترها را به صورت <code>application/x-www-form-urlencoded</code> دریافت می‌کند (نه JSON). افزونه این کار را به صورت خودکار انجام می‌دهد.
				</p>
			<?php } ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<?php $text( 'bale_token', __( 'Bale token', 'crocina-forms' ), 'text', 'placeholder="1234567890:ABCdefGHIjklMNOpqrsTUVwxyz" class="regular-text"' ); ?>
					<?php $text( 'bale_chat', __( 'Bale chat ID', 'crocina-forms' ), 'text', 'placeholder="123456789" class="regular-text"' ); ?>
					<?php $text( 'bale_endpoint', __( 'Bale endpoint', 'crocina-forms' ), 'url', 'placeholder="https://tapi.bale.ai" class="regular-text"' ); ?>
				</tbody>
			</table>
			<p>
				<button type="button" class="button button-primary crocina-test-channel-btn" data-channel="bale" data-nonce="<?php echo esc_attr( wp_create_nonce( 'crocina_test_channel' ) ); ?>"><?php esc_html_e( 'Send Test Message', 'crocina-forms' ); ?></button>
			</p>
			<div class="crocina-channel-test-result" data-channel="bale" role="status" aria-live="polite"></div>

			<!-- ========== Eitaa (existing test buttons kept) ========== -->
			<h2><?php esc_html_e( 'Eitaa', 'crocina-forms' ); ?></h2>
			<?php $guide( 'راهنمای کامل و گام‌به‌گام تنظیم ایتا (کلیک کنید)', function() { ?>
				<p><strong>مرحله ۱: ساخت ربات ایتا و دریافت توکن</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;">
					<li>به وب‌سایت <strong><a href="https://my.eitaa.com" target="_blank" rel="noreferrer noopener">my.eitaa.com</a></strong> بروید.</li>
					<li>وارد حساب کاربری ایتای خود شوید.</li>
					<li>به بخش <strong>Bot API</strong> یا <strong>ساخت ربات جدید</strong> بروید.</li>
					<li>یک <strong>نام</strong> و <strong>نام کاربری</strong> برای ربات وارد کنید.</li>
					<li>پس از ساخت، یک <strong>توکن (Token)</strong> دریافت می‌کنید — آن را کپی کنید.</li>
					<li>توکن را در فیلد <strong>«Eitaa bot token»</strong> قرار دهید.</li>
				</ol>

				<p><strong>مرحله ۲: دریافت شناسه چت (Chat ID)</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;">
					<li>برای استفاده از <strong>کانال</strong>:
						<ul style="margin:0.25rem 0 0.5rem;padding-inline-start:1.2rem;">
							<li>ربات خود را به عنوان <strong>مدیر (admin)</strong> به کانال اضافه کنید.</li>
							<li>یک پیام در کانال ارسال کنید.</li>
							<li>آدرس زیر را در مرورگر باز کنید:<br/>
							<code>https://eitaayar.ir/api/TOKEN/getUpdates</code>
							(به جای <code>TOKEN</code> توکن ربات خود را قرار دهید).</li>
							<li>در پاسخ JSON، به دنبال <code>"chat":{"id":</code> بگردید — مقدار عددی جلوی آن Chat ID است.</li>
						</ul>
					</li>
					<li>Chat ID را در فیلد <strong>«Eitaa chat ID»</strong> قرار دهید.</li>
				</ol>

				<p><strong>مرحله ۳: تأیید تنظیمات</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;">
					<li>برای تست اتصال، روی دکمه <strong>«ارسال پیام آزمایشی»</strong> در پایین همین بخش کلیک کنید.</li>
					<li>اگر نیاز به راهنمایی بیشتر برای دریافت Chat ID دارید، دکمه <strong>«راهنمای دریافت Chat ID»</strong> را بزنید.</li>
					<li>تنظیمات را ذخیره کنید.</li>
					<li>در صفحه ویرایش فرم، بخش Notifications را باز کرده و <strong>«Send payload to Eitaa webhook»</strong> را فعال کنید.</li>
				</ol>
				<p style="margin-top:0.5rem;color:#5a6577;font-size:12px;">
					<strong>نکته:</strong> API عمومی ایتا از سرویس <code>eitaayar.ir</code> استفاده می‌کند. پارامترها باید به صورت form-urlencoded ارسال شوند — افزونه این کار را خودکار انجام می‌دهد.
				</p>
			<?php } ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<?php $text( 'eitaa_token', __( 'Eitaa bot token', 'crocina-forms' ), 'text', 'placeholder="1234567890:ABCdefGHIjklMNOpqrsTUVwxyz" class="regular-text"' ); ?>
					<?php $text( 'eitaa_chat', __( 'Eitaa chat ID', 'crocina-forms' ), 'text', 'placeholder="123456789" class="regular-text"' ); ?>
				</tbody>
			</table>
			<p>
				<button type="button" class="button button-primary crocina-test-channel-btn" data-channel="eitaa" data-nonce="<?php echo esc_attr( wp_create_nonce( 'crocina_test_channel' ) ); ?>"><?php esc_html_e( 'Send Test Message', 'crocina-forms' ); ?></button>
			</p>
			<div class="crocina-channel-test-result" data-channel="eitaa" role="status" aria-live="polite"></div>

			<!-- ========== Rubika ========== -->
			<h2><?php esc_html_e( 'Rubika', 'crocina-forms' ); ?></h2>
			<?php $guide( 'راهنمای کامل و گام‌به‌گام تنظیم روبیکا (کلیک کنید)', function() { ?>
				<p><strong>مرحله ۱: ساخت ربات روبیکا و دریافت توکن</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;">
					<li>اپلیکیشن <strong>روبیکا (Rubika)</strong> را باز کنید.</li>
					<li>کاربر <strong>@BotFather</strong> را در روبیکا جستجو کنید.</li>
					<li>دستور <strong>/start</strong> را ارسال کنید.</li>
					<li>دستور <strong>/newbot</strong> را ارسال کنید.</li>
					<li>یک <strong>نام</strong> برای ربات خود وارد کنید.</li>
					<li>یک <strong>نام کاربری</strong> وارد کنید (باید به <code>bot</code> ختم شود).</li>
					<li>پس از ساخت، یک <strong>توکن (Token)</strong> دریافت می‌کنید — آن را در فیلد <strong>«Rubika token»</strong> قرار دهید.</li>
				</ol>

				<p><strong>مرحله ۲: دریافت شناسه چت (Chat ID)</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;">
					<li>اگر می‌خواهید ربات به <strong>کانال</strong> پیام ارسال کند:
						<ul style="margin:0.25rem 0 0.5rem;padding-inline-start:1.2rem;">
							<li>ربات را به عنوان مدیر به کانال اضافه کنید.</li>
							<li>یک پیام در کانال ارسال کنید.</li>
							<li>آدرس زیر را در مرورگر باز کنید:<br/>
							<code>https://botapi.rubika.ir/v3/TOKEN/getUpdates</code>
							(به جای <code>TOKEN</code> توکن خود را قرار دهید).</li>
							<li>در پاسخ JSON، به دنبال <code>"chat":{"id":</code> بگردید — مقدار آن Chat ID شماست.</li>
						</ul>
					</li>
					<li>Chat ID را در فیلد <strong>«Rubika chat ID»</strong> قرار دهید.</li>
				</ol>

				<p><strong>مرحله ۳: تنظیم endpoint</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;">
					<li>فیلد <strong>«Rubika endpoint»</strong>: آدرس <code>https://botapi.rubika.ir/v3/</code> را وارد کنید.</li>
					<li>توجه داشته باشید که نسخه API ممکن است تغییر کند — آخرین نسخه را از <a href="https://rubika.ir/botapi" target="_blank" rel="noreferrer noopener">rubika.ir/botapi</a> بررسی کنید.</li>
					<li>تنظیمات را ذخیره کنید.</li>
					<li>در صفحه ویرایش فرم، بخش Notifications را باز کرده و <strong>«Send payload to Rubika endpoint»</strong> را فعال کنید.</li>
				</ol>
				<p style="margin-top:0.5rem;color:#5a6577;font-size:12px;">
					<strong>نکته:</strong> مستندات رسمی روبیکا در <a href="https://rubika.ir/botapi" target="_blank" rel="noreferrer noopener">rubika.ir/botapi</a> در دسترس است.
				</p>
			<?php } ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<?php $text( 'rubika_token', __( 'Rubika token', 'crocina-forms' ), 'text', 'placeholder="1234567890:ABCdefGHIjklMNOpqrsTUVwxyz" class="regular-text"' ); ?>
					<?php $text( 'rubika_chat', __( 'Rubika chat ID', 'crocina-forms' ), 'text', 'placeholder="123456789" class="regular-text"' ); ?>
					<?php $text( 'rubika_endpoint', __( 'Rubika endpoint', 'crocina-forms' ), 'url', 'placeholder="https://botapi.rubika.ir/v3/" class="regular-text"' ); ?>
				</tbody>
			</table>
			<p>
				<button type="button" class="button button-primary crocina-test-channel-btn" data-channel="rubika" data-nonce="<?php echo esc_attr( wp_create_nonce( 'crocina_test_channel' ) ); ?>"><?php esc_html_e( 'Send Test Message', 'crocina-forms' ); ?></button>
			</p>
			<div class="crocina-channel-test-result" data-channel="rubika" role="status" aria-live="polite"></div>

			<!-- ========== WhatsApp ========== -->
			<h2><?php esc_html_e( 'WhatsApp', 'crocina-forms' ); ?></h2>
			<?php $guide( 'راهنمای کامل و گام‌به‌گام تنظیم واتس‌اپ (کلیک کنید)', function() { ?>
				<p><strong>مرحله ۱: ایجاد Business App در متا (Meta)</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;">
					<li>به وب‌سایت <strong><a href="https://developers.facebook.com" target="_blank" rel="noreferrer noopener">developers.facebook.com</a></strong> بروید.</li>
					<li>با حساب <strong>فیسبوک</strong> خود وارد شوید.</li>
					<li>از منوی <strong>My Apps</strong> گزینه <strong>Create App</strong> را انتخاب کنید.</li>
					<li>نوع اپلیکیشن <strong>Business</strong> را انتخاب کنید و مراحل ثبت‌نام را تکمیل کنید.</li>
				</ol>

				<p><strong>مرحله ۲: فعال‌سازی WhatsApp Cloud API</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;">
					<li>در داشبورد اپلیکیشن خود، بخش <strong>Add Product</strong> را پیدا کنید.</li>
					<li>گزینه <strong>WhatsApp</strong> را انتخاب کرده و روی <strong>Set Up</strong> کلیک کنید.</li>
					<li>با حساب <strong>Meta Business</strong> خود ارتباط برقرار کنید (در صورت نیاز یک Business Account جدید بسازید).</li>
					<li>یک <strong>شماره تلفن</strong> برای ارسال پیام‌ها ثبت کنید (می‌توانید از شماره آزمایشی متا استفاده کنید).</li>
					<li>روش <strong>send messages</strong> را انتخاب کنید.</li>
				</ol>

				<p><strong>مرحله ۳: دریافت مقادیر مورد نیاز</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;">
					<li><strong>توکن (Access Token):</strong>
						<ul style="margin:0.25rem 0 0.5rem;padding-inline-start:1.2rem;">
							<li>در بخش <strong>WhatsApp > Getting Started</strong>، یک <strong>Temporary Access Token</strong> وجود دارد — آن را کپی کنید.</li>
							<li>این توکن معمولاً با <code>EAA</code> شروع می‌شود و موقت است. برای تولید توکن دائمی، به <strong>Meta Business API</strong> مراجعه کنید.</li>
							<li>توکن را در فیلد <strong>«WhatsApp token»</strong> قرار دهید.</li>
						</ul>
					</li>
					<li><strong>شناسه تلفن (Phone Number ID):</strong>
						<ul style="margin:0.25rem 0 0.5rem;padding-inline-start:1.2rem;">
							<li>در صفحه تنظیمات WhatsApp، <strong>Phone Number ID</strong> را پیدا کنید.</li>
							<li>این مقدار یک عدد با حدود ۱۵ رقم است (مثلاً <code>123456789012345</code>).</li>
							<li>آن را در فیلد <strong>«WhatsApp chat ID»</strong> قرار دهید.</li>
						</ul>
					</li>
					<li><strong>Endpoint:</strong>
						<ul style="margin:0.25rem 0 0.5rem;padding-inline-start:1.2rem;">
							<li>آدرس endpoint به صورت زیر است:<br/>
							<code>https://graph.facebook.com/v19.0/PHONE_NUMBER_ID/messages</code></li>
							<li>به جای <code>PHONE_NUMBER_ID</code>، شناسه تلفن خود را قرار دهید.</li>
							<li>آدرس نهایی را در فیلد <strong>«WhatsApp endpoint»</strong> قرار دهید.</li>
						</ul>
					</li>
				</ol>

				<p><strong>مرحله ۴: تأیید تنظیمات</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;">
					<li>تنظیمات را ذخیره کنید.</li>
					<li>در صفحه ویرایش فرم، بخش Notifications را باز کرده و <strong>«Notify via WhatsApp (global endpoint/token)»</strong> را فعال کنید.</li>
					<li>برای تست، از دکمه <strong>«Send test notification»</strong> در همان بخش استفاده کنید.</li>
				</ol>
				<p style="margin-top:0.5rem;color:#5a6577;font-size:12px;">
					<strong>نکته:</strong> WhatsApp Cloud API از طریق <code>graph.facebook.com</code> کار می‌کند و نیاز به توکن معتبر متا دارد. کاربران ایرانی ممکن است نیاز به تحریم‌شکن (VPN) برای دسترسی به API متا داشته باشند.
				</p>
			<?php } ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<?php $text( 'whatsapp_token', __( 'WhatsApp token', 'crocina-forms' ), 'text', 'placeholder="EAAxxxxxxxxxxxxxxx" class="regular-text"' ); ?>
					<?php $text( 'whatsapp_chat', __( 'WhatsApp chat ID', 'crocina-forms' ), 'text', 'placeholder="123456789012345" class="regular-text"' ); ?>
					<?php $text( 'whatsapp_endpoint', __( 'WhatsApp endpoint', 'crocina-forms' ), 'url', 'placeholder="https://graph.facebook.com/v19.0/.../messages" class="regular-text"' ); ?>
				</tbody>
			</table>
			<p>
				<button type="button" class="button button-primary crocina-test-channel-btn" data-channel="whatsapp" data-nonce="<?php echo esc_attr( wp_create_nonce( 'crocina_test_channel' ) ); ?>"><?php esc_html_e( 'Send Test Message', 'crocina-forms' ); ?></button>
			</p>
			<div class="crocina-channel-test-result" data-channel="whatsapp" role="status" aria-live="polite"></div>

			<!-- ========== Channel Error History ========== -->
			<hr style="margin:2rem 0;" />
			<h2><?php esc_html_e( 'Channel Error History', 'crocina-forms' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'When a channel send fails (test or real), the error is logged to', 'crocina-forms' ); ?>
				<code>events.log</code>.
				<?php esc_html_e( 'View the most recent errors below.', 'crocina-forms' ); ?>
			</p>
			<p>
				<button type="button" class="button" id="crocina-err-history-refresh" data-nonce="<?php echo esc_attr( wp_create_nonce( 'crocina_get_channel_errors' ) ); ?>">
					<span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;vertical-align:middle;"></span>
					<?php esc_html_e( 'Refresh Error Log', 'crocina-forms' ); ?>
				</button>
				<label style="margin-left:0.5rem;">
					<select id="crocina-err-history-filter" style="vertical-align:baseline;">
						<option value=""><?php esc_html_e( 'All channels', 'crocina-forms' ); ?></option>
						<option value="telegram">Telegram</option>
						<option value="bale">Bale</option>
						<option value="eitaa">Eitaa</option>
						<option value="rubika">Rubika</option>
						<option value="whatsapp">WhatsApp</option>
						<option value="email">Email</option>
						<option value="webhook">Webhook</option>
					</select>
				</label>
			</p>
			<div id="crocina-err-history-list" style="max-height:400px;overflow-y:auto;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:0.75rem;font-size:12px;font-family:Consolas,Monaco,monospace;">
				<p class="description" style="margin:0;text-align:center;"><?php esc_html_e( 'Click "Refresh Error Log" to load.', 'crocina-forms' ); ?></p>
			</div>
			<p class="description" style="margin-top:0.25rem;">
				<?php esc_html_e( 'Errors are persisted in', 'crocina-forms' ); ?>
				<code>wp-content/uploads/crocina-forms/events.log</code>.
				<?php esc_html_e( 'The log is rotated at 5 MB.', 'crocina-forms' ); ?>
			</p>

			<h2><?php esc_html_e( 'Event Webhook', 'crocina-forms' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Forward all plugin events as JSON POST requests to an external endpoint.', 'crocina-forms' ); ?></p>
			<table class="form-table" role="presentation">
				<tbody>
					<?php $text( 'event_webhook_url', __( 'Webhook endpoint URL', 'crocina-forms' ), 'url', 'placeholder="https://hooks.zapier.com/..." class="regular-text"' ); ?>
					<?php $text( 'event_webhook_rate_limit', __( 'Rate limit (requests per minute)', 'crocina-forms' ), 'number', 'min="0" max="600"' ); ?>
					<?php $text( 'event_webhook_max_body_kb', __( 'Max body size (KB)', 'crocina-forms' ), 'number', 'min="0" max="1024"' ); ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Set to 0 for unlimited.', 'crocina-forms' ); ?></p>
			<?php $save_bar(); ?>
			<?php $guide( 'راهنمای جامع و گام‌به‌گام تنظیم Webhook — همراه با نمونه کد PHP, Node.js, Zapier, n8n (کلیک کنید)', function() { ?>
				<p><strong>Webhook چیست؟</strong></p>
				<p style="margin:0.25rem 0 0.75rem;line-height:1.6;">
					Webhook یک روش ارسال خودکار داده‌ها در لحظه به یک آدرس اینترنتی (endpoint) است.
					وقتی یک فرم در سایت شما ارسال می‌شود، افزونه یک درخواست HTTP POST با بدنه JSON به آدرسی که شما تعیین کرده‌اید ارسال می‌کند.
					این روش برای اتصال به سرویس‌های خارجی مثل <strong>Zapier</strong>، <strong>n8n</strong>، <strong>Slack</strong>، <strong>داشبوردهای مانیتورینگ</strong>، یا <strong>APIهای اختصاصی</strong> بسیار مفید است.
				</p>
				<p style="margin:0.25rem 0 0.75rem;line-height:1.6;">
					دو نوع وب‌هوک در افزونه وجود دارد:<br/>
					<strong>۱. وب‌هوک فرم (Form Webhook):</strong> در صفحه ویرایش هر فرم، بخش Notifications، می‌توانید endpointهای دلخواه را خط‌به‌خط وارد کنید — این وب‌هوک فقط برای همان فرم فعال است.<br/>
					<strong>۲. وب‌هوک رویداد (Event Webhook):</strong> در همین صفحه (تنظیمات سراسری) — تمام رویدادهای افزونه (ارسال فرم، ثبت لاگ، نوتیفیکیشن) را به یک endpoint واحد ارسال می‌کند.
				</p>

				<hr style="margin:1rem 0;" />

				<p><strong>مرحله ۱: ساختار Payload ارسالی</strong></p>
				<p style="margin:0.25rem 0 0.75rem;line-height:1.6;">
					بدنه هر درخواست POST یک JSON با ساختار زیر است (ممکن است بسته به نوع رویداد تغییر کند):
				</p>
				<pre style="background:#1e1e1e;color:#d4d4d4;padding:0.5rem;border-radius:4px;font-size:12px;margin-top:0.35rem;"><code>{
	"event": "crocina_form_submitted",
	"timestamp": "2026-07-24T12:00:00Z",
	"site": "https://example.com",
	"data": {
		"form_id": 5,
		"page_title": "تماس با ما",
		"page_url": "https://example.com/contact/",
		"fields": [
			{ "label": "نام", "value": "علی رضایی" },
			{ "label": "ایمیل", "value": "ali@example.com" },
			{ "label": "پیام", "value": "سلام، لطفاً تماس بگیرید." }
		],
		"submitted_at": "2026-07-24 12:00:00",
		"submitted_at_jalali": "1405-02-02 12:00:00",
		"user_ip": "192.168.1.1"
	}
}</code></pre>
				<p class="description" style="margin-top:0.25rem;">
					همه مقادیر با <code>application/json</code> و <strong>UTF-8</strong> ارسال می‌شوند.
					هدر <code>X-Crocina-Event: webhook</code> نیز به درخواست اضافه می‌شود.
					در صورت تنظیم Bearer Token، هدر <code>Authorization: Bearer YOUR_TOKEN</code> نیز ارسال می‌شود.
				</p>

				<hr style="margin:1rem 0;" />

				<p><strong>مرحله ۲: نمونه کد — دریافت Webhook با PHP</strong></p>
				<p style="margin:0.25rem 0 0.5rem;line-height:1.6;">
					این یک endpoint ساده PHP است که payload را دریافت و در فایل لاگ ذخیره می‌کند:
				</p>
				<pre style="background:#1e1e1e;color:#d4d4d4;padding:0.5rem;border-radius:4px;font-size:12px;margin-top:0.35rem;"><code>// webhook.php — آدرس این فایل را در تنظیمات Webhook وارد کنید
&lt;?php
// دریافت بدنه JSON
$input = file_get_contents("php://input");
$data  = json_decode($input, true);

if (! $data) {
    http_response_code(400);
    echo "Invalid JSON";
    exit;
}

// ثبت در فایل لاگ
$log = "[" . gmdate("Y-m-d H:i:s") . "] "
     . ($data["event"] ?? "unknown") . " | "
     . ($data["data"]["form_id"] ?? 0) . " | "
     . json_encode($data["data"]["fields"] ?? [], JSON_UNESCAPED_UNICODE)
     . PHP_EOL;

file_put_contents(__DIR__ . "/webhooks.log", $log, FILE_APPEND | LOCK_EX);

// ارسال ایمیل برای برخی رویدادها
if (strpos($data["event"] ?? "", "submitted") !== false) {
    $form_id = $data["data"]["form_id"] ?? 0;
    $fields  = $data["data"]["fields"] ?? [];
    $message = "فرم #{$form_id} ارسال شد:\n\n";
    foreach ($fields as $field) {
        $message .= $field["label"] . ": " . $field["value"] . "\n";
    }
    mail("admin@example.com", "فرم جدید - #{$form_id}", $message);
}

// پاسخ موفق
http_response_code(200);
header("Content-Type: application/json");
echo json_encode(["status" => "ok", "received" => $data["event"]]);</code></pre>

				<hr style="margin:1rem 0;" />

				<p><strong>مرحله ۳: نمونه کد — دریافت Webhook با Node.js (Express)</strong></p>
				<p style="margin:0.25rem 0 0.5rem;line-height:1.6;">
					یک سرور Express ساده که webhook را دریافت و پردازش می‌کند:
				</p>
				<pre style="background:#1e1e1e;color:#d4d4d4;padding:0.5rem;border-radius:4px;font-size:12px;margin-top:0.35rem;"><code>// server.js — با Node.js اجرا کنید: node server.js
const express = require("express");
const fs = require("fs");
const app = express();

// Middleware برای دریافت JSON (بدون محدودیت سایز)
app.use(express.json({ limit: "1mb" }));

// Webhook endpoint — آدرس: http://YOUR_IP:3000/webhook
app.post("/webhook", (req, res) => {
    const data = req.body;

    console.log("رویداد دریافت شد:", data.event);
    console.log("فرم:", data.data?.form_id);

    // ذخیره در فایل لاگ
    const logLine = JSON.stringify({
        received_at: new Date().toISOString(),
        event: data.event,
        form_id: data.data?.form_id,
    }) + "\n";

    fs.appendFileSync("webhooks.log", logLine, "utf8");

    // اگر رویداد ارسال فرم است، به تلگرام اطلاع بده
    if (data.event?.includes("submitted")) {
        const fields = data.data?.fields || [];
        let msg = "فرم جدید ارسال شد!\n";
        fields.forEach(f => {
            msg += `  ${f.label}: ${f.value}\n`;
        });
        console.log("🔔", msg);
        // می‌توانید API تلگرام، Slack، یا هر سرویس دیگر را صدا بزنید
    }

    res.status(200).json({ status: "ok" });
});

// تست سلامت
app.get("/health", (req, res) => {
    res.json({ status: "running", uptime: process.uptime() });
});

app.listen(3000, () => {
    console.log("🚀 Webhook server running on port 3000");
    console.log("📌 Endpoint: POST http://localhost:3000/webhook");
});</code></pre>

				<hr style="margin:1rem 0;" />

				<p><strong>مرحله ۴: تنظیم Webhook در Zapier</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;line-height:1.7;">
					<li>وارد حساب کاربری <strong><a href="https://zapier.com" target="_blank" rel="noreferrer noopener">Zapier</a></strong> شوید.</li>
					<li>روی <strong>Create Zap</strong> کلیک کنید.</li>
					<li>برای Trigger، اپلیکیشن <strong>Webhooks by Zapier</strong> را انتخاب کنید.</li>
					<li>Trigger Event را <strong>Catch Hook</strong> (یا <strong>Catch Raw Hook</strong>) انتخاب کنید.</li>
					<li>روی <strong>Continue</strong> کلیک کنید — Zapier یک آدرس URL منحصربه‌فرد به شما می‌دهد (مثلاً <code>https://hooks.zapier.com/hooks/catch/12345/abc123/</code>).</li>
					<li>این آدرس را کپی کرده و در فیلد <strong>«Webhook endpoint URL»</strong> در همین صفحه قرار دهید.</li>
					<li>روی <strong>Test Trigger</strong> کلیک کنید — سپس یک فرم آزمایشی در سایت خود ارسال کنید تا Zapier نمونه‌ای از payload را دریافت کند.</li>
					<li>Zapier به صورت خودکار ساختار JSON را تشخیص می‌دهد و فیلدها را برای شما نمایش می‌دهد.</li>
					<li>حالا می‌توانید یک <strong>Action</strong> اضافه کنید (مثلاً ارسال ایمیل، افزودن به Google Sheets، یا ارسال پیام به Slack).</li>
				</ol>
				<p style="margin:0.25rem 0 0.75rem;color:#5a6577;font-size:12px;">
					<strong>نکته:</strong> اگر Zapier payload را تشخیص نداد، گزینه <strong>Catch Raw Hook</strong> را انتخاب کنید — این گزینه JSON خام را دریافت می‌کند و شما می‌توانید به صورت دستی فیلدها را در Step Action مشخص کنید.
				</p>

				<hr style="margin:1rem 0;" />

				<p><strong>مرحله ۵: تنظیم Webhook در n8n</strong></p>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;line-height:1.7;">
					<li>وارد <strong>n8n</strong> خود شوید (حساب ابری یا نمونه محلی روی <code>http://localhost:5678</code>).</li>
					<li>روی <strong>New Workflow</strong> کلیک کنید.</li>
					<li>از پنل سمت چپ، نود <strong>Webhook</strong> را جستجو کرده و به صفحه کار اضافه کنید.</li>
					<li>تنظیمات نود Webhook را به صورت زیر پر کنید:</li>
				</ol>
				<table style="background:#fff;border:1px solid #ddd;border-collapse:collapse;font-size:12px;margin:0.5rem 0;">
					<tr><td style="padding:0.3rem 0.5rem;border:1px solid #ddd;font-weight:600;">HTTP Method</td><td style="padding:0.3rem 0.5rem;border:1px solid #ddd;">POST</td></tr>
					<tr><td style="padding:0.3rem 0.5rem;border:1px solid #ddd;font-weight:600;">Path</td><td style="padding:0.3rem 0.5rem;border:1px solid #ddd;"><code>/crocina-webhook</code> (دلخواه)</td></tr>
					<tr><td style="padding:0.3rem 0.5rem;border:1px solid #ddd;font-weight:600;">Response Mode</td><td style="padding:0.3rem 0.5rem;border:1px solid #ddd;"><code>Response to Webhook</code></td></tr>
					<tr><td style="padding:0.3rem 0.5rem;border:1px solid #ddd;font-weight:600;">Response Data</td><td style="padding:0.3rem 0.5rem;border:1px solid #ddd;"><code>JSON</code></td></tr>
					<tr><td style="padding:0.3rem 0.5rem;border:1px solid #ddd;font-weight:600;">JSON Output</td><td style="padding:0.3rem 0.5rem;border:1px solid #ddd;"><code>{ "status": "ok" }</code></td></tr>
					<tr><td style="padding:0.3rem 0.5rem;border:1px solid #ddd;font-weight:600;">Options</td><td style="padding:0.3rem 0.5rem;border:1px solid #ddd;">Raw Body ✅</td></tr>
				</table>
				<ol style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;line-height:1.7;" start="5">
					<li>روی دکمه <strong>Listen for test event</strong> کلیک کنید — n8n منتظر یک درخواست آزمایشی می‌ماند.</li>
					<li>آدرس Webhook تولیدشده توسط n8n را کپی کنید (مثلاً <code>https://YOUR-INSTANCE.app.n8n.cloud/webhook/crocina-webhook</code>).</li>
					<li>این آدرس را در فیلد <strong>«Webhook endpoint URL»</strong> در همین صفحه قرار دهید و تنظیمات را ذخیره کنید.</li>
					<li>یک فرم آزمایشی در سایت خود ارسال کنید — n8n payload را دریافت می‌کند و ساختار داده را نمایش می‌دهد.</li>
					<li>حالا می‌توانید نودهای دیگری به workflow اضافه کنید: <strong>IF</strong> برای شرط‌گذاری، <strong>Telegram</strong> برای ارسال پیام، <strong>Google Sheets</strong> برای ذخیره داده، یا <strong>HTTP Request</strong> برای اتصال به APIهای دیگر.</li>
				</ol>
				<p style="margin:0.25rem 0 0.75rem;color:#5a6577;font-size:12px;">
					<strong>نکته:</strong> در n8n می‌توانید از <strong>Response Headers</strong> برای تنظیم هدر <code>X-Crocina-Event</code> استفاده کنید تا رویدادهای مختلف را مسیریابی کنید.
				</p>

				<hr style="margin:1rem 0;" />

				<p><strong>مرحله ۶: عیب‌یابی (Troubleshooting)</strong></p>
				<ul style="margin:0.25rem 0 0.75rem;padding-inline-start:1.2rem;line-height:1.7;">
					<li><strong>Endpoint 404 می‌دهد:</strong> مطمئن شوید آدرس endpoint درست و قابل دسترس از اینترنت است (برای localhost نیاز به ngrok یا تونل مشابه دارید).</li>
					<li><strong>هیچ درخواستی ارسال نمی‌شود:</strong> بررسی کنید فیلد URL خالی نباشد و تنظیمات ذخیره شده باشد.</li>
					<li><strong>HTTP 401 / 403:</strong> اگر endpoint نیاز به احراز هویت دارد، از فیلد <strong>Bearer Token</strong> برای ارسال توکن استفاده کنید.</li>
					<li><strong>Payload خیلی بزرگ:</strong> محدودیت <strong>Max body size</strong> را افزایش دهید یا endpoint خود را به گونه‌ای تغییر دهید که payloadهای بزرگ را بپذیرد.</li>
					<li><strong>Rate limit exceeded:</strong> اگر endpoint شما محدودیت نرخ دارد، مقدار <strong>Rate limit</strong> را کاهش دهید.</li>
					<li><strong>خطا در سرورهای اشتراکی:</strong> برخی هاست‌ها اجازه <code>wp_remote_post()</code> به آدرس‌های خارجی را نمی‌دهند — با پشتیبانی هاست خود تماس بگیرید.</li>
				</ul>

				<p style="margin-top:0.75rem;color:#5a6577;font-size:12px;">
					<strong>نکته امنیتی:</strong> برای endpointهای خصوصی، حتماً از <strong>Bearer Token</strong> استفاده کنید تا فقط افزونه شما بتواند به endpoint درخواست ارسال کند.
					همچنین توصیه می‌شود endpoint شما از پروتکل <strong>HTTPS</strong> استفاده کند تا داده‌ها به صورت رمزنگاری‌شده منتقل شوند.
				</p>
			<?php } ); ?>
		</div>

		<div class="tab-content" id="tab-security" style="display:none;margin-top:1rem;">
			<table class="form-table" role="presentation">
				<tbody>
					<?php $text( 'rate_limit', __( 'Rate limit (submissions per window)', 'crocina-forms' ), 'number', 'min="1"' ); ?>
					<?php $text( 'rate_limit_window', __( 'Rate limit window (seconds)', 'crocina-forms' ), 'number', 'min="60"' ); ?>
					<?php $text( 'min_delay_seconds', __( 'Minimum delay (seconds)', 'crocina-forms' ), 'number', 'min="1"' ); ?>
					<?php $text( 'spam_honeypot_name', __( 'Honeypot field name', 'crocina-forms' ) ); ?>
				</tbody>
			</table>
			<?php $save_bar(); ?>
		</div>

		<div class="tab-content" id="tab-attachments" style="display:none;margin-top:1rem;">
			<table class="form-table" role="presentation">
				<tbody>
					<?php $checkbox( 'attachments_enabled', __( 'Enable file attachments for admin email', 'crocina-forms' ) ); ?>
					<?php $text( 'attachments_max_mb', __( 'Max attachment size (MB)', 'crocina-forms' ), 'number', 'min="1" max="25"' ); ?>
					<?php $text( 'attachments_allowed_types', __( 'Allowed file types (comma separated)', 'crocina-forms' ), 'text', 'placeholder="jpg,jpeg,png,pdf"' ); ?>
				</tbody>
			</table>
			<?php $save_bar(); ?>
		</div>

		<div class="tab-content" id="tab-watermark" style="display:none;margin-top:1rem;">
			<?php $checkbox( 'watermark_enabled', __( 'Enable image watermarking', 'crocina-forms' ) ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<?php $select( 'watermark_type', __( 'Watermark type', 'crocina-forms' ), array(
						'text' => __( 'Text only', 'crocina-forms' ),
						'logo' => __( 'Logo only', 'crocina-forms' ),
						'both' => __( 'Text + Logo', 'crocina-forms' ),
					) ); ?>
					<?php $text( 'watermark_text', __( 'Watermark text', 'crocina-forms' ), 'text', 'placeholder="' . esc_attr( get_bloginfo( 'name' ) ) . '"' ); ?>
					<?php $text( 'watermark_font_size', __( 'Font size (px)', 'crocina-forms' ), 'number', 'min="8" max="128"' ); ?>
					<?php $text( 'watermark_color', __( 'Text color (hex)', 'crocina-forms' ), 'text', 'placeholder="#ffffff" pattern="^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$"' ); ?>
					<?php $text( 'watermark_opacity', __( 'Text opacity (5–100)', 'crocina-forms' ), 'number', 'min="5" max="100"' ); ?>
					<?php $select( 'watermark_position', __( 'Watermark position', 'crocina-forms' ), array(
						'bottom-right' => __( 'Bottom right', 'crocina-forms' ),
						'bottom-left'  => __( 'Bottom left', 'crocina-forms' ),
						'top-right'    => __( 'Top right', 'crocina-forms' ),
						'top-left'     => __( 'Top left', 'crocina-forms' ),
						'center'       => __( 'Center', 'crocina-forms' ),
					) ); ?>
					<?php $text( 'watermark_logo_max_width', __( 'Logo max width (px)', 'crocina-forms' ), 'number', 'min="16" max="600"' ); ?>
					<?php $text( 'watermark_logo_opacity', __( 'Logo opacity (5–100)', 'crocina-forms' ), 'number', 'min="5" max="100"' ); ?>
				</tbody>
			</table>

			<?php
			$current_logo_id = absint( $settings['watermark_logo_id'] ?? 0 );
			$logo_url = $current_logo_id ? wp_get_attachment_image_url( $current_logo_id, 'medium' ) : '';
			$current_sample_id = absint( $settings['watermark_sample_image_id'] ?? 0 );
			$sample_url = $current_sample_id ? wp_get_attachment_image_url( $current_sample_id, 'medium' ) : '';
			?>
			<h3><?php esc_html_e( 'Watermark Logo', 'crocina-forms' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Upload a transparent PNG logo. Recommended max width: 120px.', 'crocina-forms' ); ?></p>
			<input type="hidden" name="crocina_forms_settings[watermark_logo_id]" id="crocina-watermark-logo-id" value="<?php echo esc_attr( $current_logo_id ); ?>" />
			<div id="crocina-logo-preview"<?php echo $logo_url ? '' : ' style="display:none;"'; ?>>
				<img src="<?php echo esc_url( $logo_url ); ?>" alt="" style="max-height:60px;vertical-align:middle;" />
				<button type="button" class="button-link" id="crocina-logo-remove"><?php esc_html_e( 'Remove', 'crocina-forms' ); ?></button>
			</div>
			<button type="button" class="button" id="crocina-logo-upload-btn"<?php echo $logo_url ? ' style="display:none;"' : ''; ?>><?php esc_html_e( 'Upload Logo', 'crocina-forms' ); ?></button>

			<h3><?php esc_html_e( 'Custom sample image for preview', 'crocina-forms' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Upload a custom image to see how the watermark looks.', 'crocina-forms' ); ?></p>
			<input type="hidden" name="crocina_forms_settings[watermark_sample_image_id]" id="crocina-watermark-sample-id" value="<?php echo esc_attr( $current_sample_id ); ?>" />
			<div id="crocina-sample-preview"<?php echo $sample_url ? '' : ' style="display:none;"'; ?>>
				<img src="<?php echo esc_url( $sample_url ?: '' ); ?>" alt="" style="max-height:60px;vertical-align:middle;border-radius:4px;" />
				<button type="button" class="button-link" id="crocina-sample-remove"><?php esc_html_e( 'Remove', 'crocina-forms' ); ?></button>
			</div>
			<button type="button" class="button" id="crocina-sample-upload-btn"<?php echo $sample_url ? ' style="display:none;"' : ''; ?>><?php esc_html_e( 'Upload Sample Image', 'crocina-forms' ); ?></button>

			<?php
			$preview_url = add_query_arg( array(
				'action'          => 'crocina_watermark_preview',
				'type'            => rawurlencode( $settings['watermark_type'] ?? 'text' ),
				'text'            => rawurlencode( ! empty( $settings['watermark_text'] ) ? $settings['watermark_text'] : get_bloginfo( 'name' ) ),
				'position'        => $settings['watermark_position'] ?? 'bottom-right',
				'opacity'         => $settings['watermark_opacity'] ?? 40,
				'font_size'       => $settings['watermark_font_size'] ?? 24,
				'color'           => rawurlencode( $settings['watermark_color'] ?? '#ffffff' ),
				'logo_id'         => $current_logo_id,
				'logo_max_width'  => $settings['watermark_logo_max_width'] ?? 120,
				'logo_opacity'    => $settings['watermark_logo_opacity'] ?? 60,
				'sample_id'       => $current_sample_id,
			), admin_url( 'admin-ajax.php' ) );
			?>
			<h3><?php esc_html_e( 'Live Preview', 'crocina-forms' ); ?></h3>
			<img id="crocina-watermark-preview-img" src="<?php echo esc_url( $preview_url ); ?>" alt="" width="480" height="320" style="max-width:100%;height:auto;border:1px solid #ddd;border-radius:4px;" />
			<?php if ( ! function_exists( 'imagecreatetruecolor' ) ) : ?>
				<p class="description" style="color:#d63638;"><?php esc_html_e( 'GD library is not installed. Preview unavailable.', 'crocina-forms' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Batch Watermark', 'crocina-forms' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Apply watermark to all existing media library images.', 'crocina-forms' ); ?></p>
			<p>
				<button type="button" class="button button-primary" id="crocina-batch-start"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'crocina_batch_watermark' ) ); ?>"
					<?php echo empty( $settings['watermark_enabled'] ) ? 'disabled' : ''; ?>>
					<?php esc_html_e( 'Apply Watermark to All Images', 'crocina-forms' ); ?>
				</button>
			</p>
			<div id="crocina-batch-progress" style="display:none;">
				<div style="background:#ddd;height:8px;border-radius:4px;overflow:hidden;margin:0.5rem 0;">
					<div id="crocina-batch-bar-fill" style="background:#2271b1;height:100%;width:0%;transition:width 0.3s;"></div>
				</div>
				<p id="crocina-batch-status" class="description"><?php esc_html_e( 'Scanning…', 'crocina-forms' ); ?></p>
				<div id="crocina-batch-results"></div>
			</div>
		</div>

		<div class="tab-content" id="tab-system" style="display:none;margin-top:1rem;">
			<?php $save_bar(); ?>
			<div class="crocina-system-toolbar">
				<button type="button" id="crocina-system-refresh" class="button" data-nonce="<?php echo esc_attr( wp_create_nonce( 'crocina_system_refresh' ) ); ?>">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh', 'crocina-forms' ); ?>
				</button>
				<span id="crocina-system-timestamp" class="crocina-system-ts"></span>
			</div>

			<div id="crocina-system-content">

			<?php
			$forms_count = wp_count_posts( 'crocina_form' )->publish ?? 0;
			$logs_count  = 0;
			$unread_count = 0;
			$settings_total = ( $stats['settings_db_calls'] ?? 0 ) + ( $stats['settings_cache_hits'] ?? 0 );
			$settings_hit_pct = $settings_total > 0 ? round( ( $stats['settings_cache_hits'] ?? 0 ) / $settings_total * 100 ) : 0;
			$design_batched = $stats['design_preloaded'] ?? 0;
			$design_indiv = $stats['design_db_calls'] ?? 0;
			$design_total = $design_batched + $design_indiv;
			$design_batch_pct = $design_total > 0 ? round( $design_batched / $design_total * 100 ) : 0;
			?>

			<!-- ====== 1. Overview Cards ====== -->
			<div class="crocina-system-grid">
				<div class="crocina-system-card">
					<div class="crocina-system-card-icon dashicons dashicons-feedback"></div>
					<div class="crocina-system-card-body">
						<span class="crocina-system-card-value"><?php echo esc_html( number_format_i18n( $forms_count ) ); ?></span>
						<span class="crocina-system-card-label"><?php esc_html_e( 'Published Forms', 'crocina-forms' ); ?></span>
					</div>
				</div>
				<div class="crocina-system-card crocina-system-card-db">
					<div class="crocina-system-card-icon dashicons dashicons-database"></div>
					<div class="crocina-system-card-body">
						<span class="crocina-system-card-value crocina-system-card-value-db">-</span>
						<span class="crocina-system-card-label"><?php esc_html_e( 'Total Logs', 'crocina-forms' ); ?></span>
					</div>
				</div>
				<div class="crocina-system-card crocina-system-card-unread">
					<div class="crocina-system-card-icon dashicons dashicons-visibility"></div>
					<div class="crocina-system-card-body">
						<span class="crocina-system-card-value crocina-system-card-value-unread">-</span>
						<span class="crocina-system-card-label"><?php esc_html_e( 'Unread Logs', 'crocina-forms' ); ?></span>
					</div>
				</div>
				<div class="crocina-system-card">
					<div class="crocina-system-card-icon dashicons dashicons-admin-generic"></div>
					<div class="crocina-system-card-body">
						<span class="crocina-system-card-value crocina-system-card-value-cache"><?php esc_html_e( '—', 'crocina-forms' ); ?></span>
						<span class="crocina-system-card-label"><?php esc_html_e( 'Cache Backend', 'crocina-forms' ); ?></span>
					</div>
				</div>
			</div>

			<!-- ====== 2. Cache Performance ====== -->
			<div class="crocina-system-section">
				<div class="crocina-system-section-title">
					<span class="dashicons dashicons-performance"></span>
					<?php esc_html_e( 'Cache Performance', 'crocina-forms' ); ?>
				</div>
				<div class="crocina-system-section-body">
					<div class="crocina-system-two-col">
						<div class="crocina-metric-card">
							<div class="crocina-metric-header">
								<span><?php esc_html_e( 'Settings Cache', 'crocina-forms' ); ?></span>
								<span class="crocina-system-badge <?php echo $settings_hit_pct >= 80 ? 'crocina-system-badge-ok' : ( $settings_hit_pct >= 50 ? 'crocina-system-badge-warn' : 'crocina-system-badge-poor' ); ?>"><?php echo esc_html( $settings_hit_pct ); ?>%</span>
							</div>
							<div class="crocina-progress-bar">
								<div class="crocina-progress-fill crocina-progress-fill-blue" style="width:<?php echo esc_attr( $settings_hit_pct ); ?>%;"></div>
							</div>
							<div class="crocina-metric-details">
								<span><strong><?php esc_html_e( 'DB', 'crocina-forms' ); ?>:</strong> <?php echo esc_html( $stats['settings_db_calls'] ?? 0 ); ?></span>
								<span><strong><?php esc_html_e( 'Hits', 'crocina-forms' ); ?>:</strong> <?php echo esc_html( $stats['settings_cache_hits'] ?? 0 ); ?></span>
							</div>
						</div>
						<div class="crocina-metric-card">
							<div class="crocina-metric-header">
								<span><?php esc_html_e( 'Design Cache', 'crocina-forms' ); ?></span>
								<span class="crocina-system-badge <?php echo $design_batch_pct >= 80 ? 'crocina-system-badge-ok' : ( $design_batch_pct >= 50 ? 'crocina-system-badge-warn' : 'crocina-system-badge-poor' ); ?>"><?php echo esc_html( $design_batch_pct ); ?>%</span>
							</div>
							<div class="crocina-progress-bar">
								<div class="crocina-progress-fill crocina-progress-fill-green" style="width:<?php echo esc_attr( $design_batch_pct ); ?>%;"></div>
							</div>
							<div class="crocina-metric-details">
								<span><strong><?php esc_html_e( 'Batch', 'crocina-forms' ); ?>:</strong> <?php echo esc_html( $design_batched ); ?></span>
								<span><strong><?php esc_html_e( 'Individual', 'crocina-forms' ); ?>:</strong> <?php echo esc_html( $design_indiv ); ?></span>
							</div>
						</div>
					</div>
					<div class="crocina-system-note">
						<span class="dashicons dashicons-info-outline"></span>
						<?php esc_html_e( 'Cache backend:', 'crocina-forms' ); ?>
						<strong class="crocina-cache-backend-label"><?php echo isset( $stats['cache_backend'] ) && 'persistent' === $stats['cache_backend'] ? esc_html__( 'Persistent (Redis/Memcached)', 'crocina-forms' ) : esc_html__( 'Default (transient fallback)', 'crocina-forms' ); ?></strong>
					</div>
				</div>
			</div>

			<!-- ====== 3. Database Status ====== -->
			<div class="crocina-system-section">
				<div class="crocina-system-section-title">
					<span class="dashicons dashicons-database"></span>
					<?php esc_html_e( 'Database Status', 'crocina-forms' ); ?>
				</div>
				<div class="crocina-system-section-body">
					<?php if ( ! $db_status ) : ?>
						<p class="description"><?php esc_html_e( 'Could not load database status.', 'crocina-forms' ); ?></p>
					<?php else : ?>
						<div class="crocina-db-grid">
							<div class="crocina-db-item">
								<span class="crocina-db-label"><?php esc_html_e( 'Server', 'crocina-forms' ); ?></span>
								<span class="crocina-db-value"><code><?php echo esc_html( $db_status['db_server'] ); ?></code></span>
							</div>
							<div class="crocina-db-item">
								<span class="crocina-db-label"><?php esc_html_e( 'Engine', 'crocina-forms' ); ?></span>
								<span class="crocina-db-value"><code><?php echo esc_html( $db_status['db_engine'] ); ?></code></span>
							</div>
							<div class="crocina-db-item">
								<span class="crocina-db-label"><?php esc_html_e( 'Schema', 'crocina-forms' ); ?></span>
								<span class="crocina-db-value"><code><?php echo esc_html( $db_status['db_version'] ); ?></code></span>
							</div>
							<div class="crocina-db-item">
								<span class="crocina-db-label"><?php esc_html_e( 'FULLTEXT', 'crocina-forms' ); ?></span>
								<span class="crocina-db-value">
									<?php if ( isset( $db_status['fulltext_ok'] ) ) : ?>
										<span class="crocina-status-dot <?php echo $db_status['fulltext_ok'] ? 'crocina-status-ok' : 'crocina-status-err'; ?>"></span>
										<?php echo esc_html( $db_status['fulltext_note'] ?? '' ); ?>
									<?php else : ?>
										<?php esc_html_e( 'Not tested', 'crocina-forms' ); ?>
									<?php endif; ?>
								</span>
							</div>
							<?php if ( ! empty( $db_status['indexes'] ) ) : ?>
								<div class="crocina-db-item crocina-db-item-full">
									<span class="crocina-db-label"><?php esc_html_e( 'Indexes', 'crocina-forms' ); ?></span>
									<span class="crocina-db-value">
										<?php foreach ( $db_status['indexes'] as $idx ) : ?>
											<code class="crocina-idx-tag"><?php echo esc_html( $idx['name'] ); ?></code>
										<?php endforeach; ?>
									</span>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $db_status['legacy_remain'] ) ) : ?>
								<div class="crocina-db-item crocina-db-item-full">
									<span class="crocina-db-label" style="color:#d63638;">
										<span class="crocina-status-dot crocina-status-err"></span>
										<?php esc_html_e( 'Legacy indexes', 'crocina-forms' ); ?>
									</span>
									<span class="crocina-db-value">
										<?php foreach ( $db_status['legacy_remain'] as $legacy ) : ?>
											<code class="crocina-idx-tag" style="color:#d63638;border-color:#f5c6cb;"><?php echo esc_html( $legacy ); ?></code>
										<?php endforeach; ?>
									</span>
								</div>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- ====== 4. Capability Mapping ====== -->
			<div class="crocina-system-section crocina-system-section-cap">
				<div class="crocina-system-section-title">
					<span class="dashicons dashicons-admin-users"></span>
					<?php esc_html_e( 'Capability Mapping', 'crocina-forms' ); ?>
				</div>
				<div class="crocina-system-section-body">
					<p class="description"><?php esc_html_e( 'Current user capabilities used by the plugin.', 'crocina-forms' ); ?></p>
					<div class="crocina-cap-grid" id="crocina-cap-grid">
						<div class="crocina-cap-row"><span class="crocina-cap-name">manage_options</span><span class="crocina-cap-loader"></span></div>
						<div class="crocina-cap-row"><span class="crocina-cap-name">edit_posts</span><span class="crocina-cap-loader"></span></div>
						<div class="crocina-cap-row"><span class="crocina-cap-name">edit_post</span><span class="crocina-cap-loader"></span></div>
						<div class="crocina-cap-row"><span class="crocina-cap-name">unfiltered_html</span><span class="crocina-cap-loader"></span></div>
					</div>
				</div>
			</div>

			<!-- ====== 5. REST API Health ====== -->
			<div class="crocina-system-section crocina-system-section-rest">
				<div class="crocina-system-section-title">
					<span class="dashicons dashicons-rest-api"></span>
					<?php esc_html_e( 'REST API Health', 'crocina-forms' ); ?>
				</div>
				<div class="crocina-system-section-body">
					<p class="description"><?php esc_html_e( 'Registered REST API endpoints under crocina/v1.', 'crocina-forms' ); ?></p>
					<div class="crocina-rest-grid" id="crocina-rest-grid">
						<div class="crocina-rest-row"><span class="crocina-rest-method">GET</span><code>/crocina/v1/forms</code><span class="crocina-rest-loader"></span></div>
						<div class="crocina-rest-row"><span class="crocina-rest-method">GET</span><code>/crocina/v1/forms/{id}</code><span class="crocina-rest-loader"></span></div>
						<div class="crocina-rest-row"><span class="crocina-rest-method">GET</span><code>/crocina/v1/forms/{id}/fields</code><span class="crocina-rest-loader"></span></div>
						<div class="crocina-rest-row"><span class="crocina-rest-method">POST</span><code>/crocina/v1/forms/{id}/reorder-fields</code><span class="crocina-rest-loader"></span></div>
					</div>
				</div>
			</div>

			<!-- ====== 6. WP-CLI Commands ====== -->
			<div class="crocina-system-section crocina-system-section-cli">
				<div class="crocina-system-section-title">
					<span class="dashicons dashicons-editor-code"></span>
					<?php esc_html_e( 'WP-CLI Commands', 'crocina-forms' ); ?>
				</div>
				<div class="crocina-system-section-body">
					<p class="description"><?php esc_html_e( 'Available CLI commands. Run with', 'crocina-forms' ); ?> <code>wp crocina &lt;command&gt; --help</code>.</p>
					<div class="crocina-cli-grid" id="crocina-cli-grid">
						<div class="crocina-cli-row">
							<code class="crocina-cli-cmd">crocina cache</code>
							<span class="crocina-cli-desc"><?php esc_html_e( 'Flush, warm, or show cache status', 'crocina-forms' ); ?></span>
						</div>
						<div class="crocina-cli-row">
							<code class="crocina-cli-cmd">crocina events</code>
							<span class="crocina-cli-desc"><?php esc_html_e( 'Export, stats, webhook test', 'crocina-forms' ); ?></span>
						</div>
						<div class="crocina-cli-row">
							<code class="crocina-cli-cmd">crocina form</code>
							<span class="crocina-cli-desc"><?php esc_html_e( 'List forms with field/cache info', 'crocina-forms' ); ?></span>
						</div>
						<div class="crocina-cli-row">
							<code class="crocina-cli-cmd">crocina warm-cache</code>
							<span class="crocina-cli-desc"><?php esc_html_e( 'Pre-build cache for all forms', 'crocina-forms' ); ?></span>
						</div>
						<div class="crocina-cli-row">
							<code class="crocina-cli-cmd">crocina preload</code>
							<span class="crocina-cli-desc"><?php esc_html_e( 'Alias for warm-cache', 'crocina-forms' ); ?></span>
						</div>
						<div class="crocina-cli-row">
							<code class="crocina-cli-cmd">crocina webhook</code>
							<span class="crocina-cli-desc"><?php esc_html_e( 'Send test webhook', 'crocina-forms' ); ?></span>
						</div>
						<div class="crocina-cli-row">
							<code class="crocina-cli-cmd">crocina security</code>
							<span class="crocina-cli-desc"><?php esc_html_e( 'Scan handlers for nonce/cap', 'crocina-forms' ); ?></span>
						</div>
					</div>
				</div>
			</div>

			<!-- ====== 7. Error Log Guard ====== -->
			<div class="crocina-system-section crocina-system-section-err">
				<div class="crocina-system-section-title">
					<span class="dashicons dashicons-shield"></span>
					<?php esc_html_e( 'Error Log Guard', 'crocina-forms' ); ?>
				</div>
				<div class="crocina-system-section-body">
					<div class="crocina-err-grid" id="crocina-err-grid">
						<div class="crocina-err-row">
							<span><?php esc_html_e( 'WP_DEBUG', 'crocina-forms' ); ?></span>
							<span class="crocina-err-loader"></span>
						</div>
						<div class="crocina-err-row">
							<span><?php esc_html_e( 'WP_DEBUG_LOG', 'crocina-forms' ); ?></span>
							<span class="crocina-err-loader"></span>
						</div>
						<div class="crocina-err-row">
							<span><?php esc_html_e( 'WP_DEBUG_DISPLAY', 'crocina-forms' ); ?></span>
							<span class="crocina-err-loader"></span>
						</div>
						<div class="crocina-err-row">
							<span><?php esc_html_e( 'Status', 'crocina-forms' ); ?></span>
							<span class="crocina-err-note crocina-err-loader"></span>
						</div>
					</div>
				</div>
			</div>

			<!-- ====== 8. Environment ====== -->
			<div class="crocina-system-section crocina-system-section-env">
				<div class="crocina-system-section-title">
					<span class="dashicons dashicons-admin-site"></span>
					<?php esc_html_e( 'Environment', 'crocina-forms' ); ?>
				</div>
				<div class="crocina-system-section-body">
					<div class="crocina-env-grid" id="crocina-env-grid">
						<div class="crocina-env-row"><span class="crocina-env-label">PHP</span><span class="crocina-env-value" data-env-key="php"><?php echo esc_html( phpversion() ); ?></span></div>
						<div class="crocina-env-row"><span class="crocina-env-label">WordPress</span><span class="crocina-env-value" data-env-key="wp"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span></div>
						<div class="crocina-env-row"><span class="crocina-env-label"><?php esc_html_e( 'Memory', 'crocina-forms' ); ?></span><span class="crocina-env-value" data-env-key="memory"><?php echo esc_html( ini_get( 'memory_limit' ) ); ?></span></div>
						<div class="crocina-env-row"><span class="crocina-env-label"><?php esc_html_e( 'Max exec', 'crocina-forms' ); ?></span><span class="crocina-env-value" data-env-key="max_execution"><?php echo esc_html( ini_get( 'max_execution_time' ) . 's' ); ?></span></div>
						<div class="crocina-env-row"><span class="crocina-env-label"><?php esc_html_e( 'Upload max', 'crocina-forms' ); ?></span><span class="crocina-env-value" data-env-key="uploads_max"><?php echo esc_html( ini_get( 'upload_max_filesize' ) ); ?></span></div>
						<div class="crocina-env-row"><span class="crocina-env-label"><?php esc_html_e( 'Post max', 'crocina-forms' ); ?></span><span class="crocina-env-value" data-env-key="post_max"><?php echo esc_html( ini_get( 'post_max_size' ) ); ?></span></div>
						<div class="crocina-env-row"><span class="crocina-env-label"><?php esc_html_e( 'Object cache', 'crocina-forms' ); ?></span><span class="crocina-env-value" data-env-key="object_cache"><?php echo wp_using_ext_object_cache() ? esc_html__( 'Active', 'crocina-forms' ) : esc_html__( 'Inactive', 'crocina-forms' ); ?></span></div>
					</div>
				</div>
			</div>

			</div><!-- /crocina-system-content -->
		</div>

	</form>
</div>

<script>
jQuery( function ( $ ) {
	'use strict';

	/* ---- Watermark type toggle ---- */
	var $type = $('select[name="crocina_forms_settings[watermark_type]"]');
	if($type.length){
		var toggleLogoFields = function(){
			var v = $type.val();
			$('#crocina-watermark-logo-fields, #crocina-watermark-logo-upload').toggle(v === 'logo' || v === 'both');
		};
		$type.on('change', toggleLogoFields);
	}

	/* ---- In-tab Save via AJAX ---- */
	var $settingsForm = $('form[action="options.php"]');
	var saveNonce = '<?php echo esc_js( wp_create_nonce( 'crocina_save_settings' ) ); ?>';
	if ( $settingsForm.length ) {
		$settingsForm.on( 'submit', function ( event ) {
			var $btn = $( event.originalEvent ? event.target : document.activeElement );
			// Only intercept when the submit button carries our class.
			if ( ! $btn.is( '.crocina-save-settings' ) && ! $btn.closest( '.crocina-save-settings' ).length ) {
				return; // Let normal browser submit through.
			}
			event.preventDefault();

			var $form   = $( this );
			var $fb     = $form.find( '.crocina-save-feedback' );
			var $barBtn = $form.find( '.crocina-save-settings' ).prop( 'disabled', true );

			$fb.html( '<span class="spinner" style="float:none;margin-top:0;visibility:visible;"></span> ' ).addClass( 'crocina-saving' );

			$.post( ajaxurl, {
				action: 'crocina_save_settings',
				nonce: saveNonce,
				settings: $form.serialize()
			} )
				.done( function ( resp ) {
					if ( resp.success ) {
						$fb.removeClass( 'crocina-saving' ).html( '<span class="dashicons dashicons-yes-alt" style="color:#2e7d32;"></span> ' + ( resp.data.message || 'Saved successfully.' ) );
						// Update the settings_errors notices inline by reloading them.
						$form.find( '.settings-error' ).remove();
					} else {
						$fb.removeClass( 'crocina-saving' ).html( '<span class="dashicons dashicons-warning" style="color:#d32f2f;"></span> ' + ( resp.data && resp.data.message ? resp.data.message : 'Save failed.' ) );
					}
					window.setTimeout( function () {
						$fb.empty();
						$barBtn.prop( 'disabled', false );
					}, 3000 );
				} )
				.fail( function () {
					$fb.removeClass( 'crocina-saving' ).html( '<span class="dashicons dashicons-warning" style="color:#d32f2f;"></span> Network error. Please try again.' );
					$barBtn.prop( 'disabled', false );
				} );
		} );
	}
});
</script>
