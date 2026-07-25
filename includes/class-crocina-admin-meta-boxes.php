<?php
/**
 * Admin Meta Boxes — form builder, design, and notification meta boxes.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

class Crocina_Admin_Meta_Boxes {

	use Crocina_Input_Helper;

	/**
	 * Application container.
	 *
	 * @var Crocina_App
	 */
	private $app;

	/**
	 * @param Crocina_App $app
	 */
	public function __construct( Crocina_App $app ) {
		$this->app = $app;
	}

	/**
	 * Register WordPress hooks for meta boxes.
	 */
	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_crocina_form', array( $this, 'save_form_meta' ), 10, 2 );
	}

	/**
	 * Register the meta boxes used on the form editor screen.
	 *
	 * Only the fields-builder and notifications run as standalone meta boxes.
	 * The design panel is rendered inline inside builder-canvas.php to avoid
	 * duplication and keep the form editor in a single scrollable flow.
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'crocina-fields-builder',
			__( 'Build your form visually', 'crocina-forms' ),
			array( $this, 'render_fields_meta_box' ),
			'crocina_form',
			'normal',
			'high'
		);
		add_meta_box(
			'crocina-notifications',
			__( 'Notifications', 'crocina-forms' ),
			array( $this, 'render_notifications_meta_box' ),
			'crocina_form',
			'side',
			'default'
		);
	}

	/**
	 * Render the fields builder meta box.
	 */
	public function render_fields_meta_box( $post ) {
		$fields = get_post_meta( $post->ID, 'crocina_fields', true );
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}
		$current_slug = $post->post_name ?: '';
		wp_nonce_field( 'crocina_form_meta', 'crocina_form_meta_nonce' );
		$render_field_card = function ( $field = array(), $is_template = false ) {
			$this->render_field_card( $field, $is_template );
		};
		$render_design = function( $post ) {
			$this->render_design_meta_box( $post );
		};
		$render_notifications = function( $post ) {
			$this->render_notifications_meta_box( $post );
		};
		echo $this->app->resolve( 'render' )->render(
			'admin/builder-canvas',
			array(
				'fields'               => $fields,
				'current_slug'         => $current_slug,
				'field_types'          => $this->app->resolve( 'admin' )->get_field_types(),
				'post'                 => $post,
				'render_field_card'    => $render_field_card,
				'render_design'        => $render_design,
				'render_notifications' => $render_notifications,
			)
		);
	}

	/**
	 * Render the design & appearance meta box.
	 */
	/**
	 * Render the design & appearance panel.
	 *
	 * All controls are laid out in a compact responsive grid. The live preview
	 * iframe has been removed in favour of a simple preview button card at the
	 * top so editors can see colour/text changes immediately.
	 */
	public function render_design_meta_box( $post ) {
		$design = $this->app->resolve( 'core' )->get_form_design( $post->ID );
		$allowed_themes = array(
			'modern'  => __( 'Modern', 'crocina-forms' ),
			'classic' => __( 'Classic', 'crocina-forms' ),
			'minimal' => __( 'Minimal', 'crocina-forms' ),
		);
		$current_theme = $design['theme'] ?? 'modern';
		if ( ! array_key_exists( $current_theme, $allowed_themes ) ) {
			$current_theme = 'modern';
		}
		$allowed_templates = array(
			'default'  => __( 'Default', 'crocina-forms' ),
			'card'     => __( 'Card', 'crocina-forms' ),
			'minimal'  => __( 'Minimal', 'crocina-forms' ),
			'bordered' => __( 'Bordered', 'crocina-forms' ),
			'shadow'   => __( 'Shadow', 'crocina-forms' ),
		);
		$current_template = $design['template'] ?? 'default';
		if ( ! array_key_exists( $current_template, $allowed_templates ) ) {
			$current_template = 'default';
		}

		$icons = array(
			'dashicons-email'           => __( 'Email', 'crocina-forms' ),
			'dashicons-format-chat'     => __( 'Chat', 'crocina-forms' ),
			'dashicons-phone'           => __( 'Phone', 'crocina-forms' ),
			'dashicons-yes'             => __( 'Check', 'crocina-forms' ),
			'dashicons-arrow-right-alt' => __( 'Arrow', 'crocina-forms' ),
			'dashicons-share'           => __( 'Share', 'crocina-forms' ),
			'dashicons-flag'            => __( 'Flag', 'crocina-forms' ),
			'dashicons-star-filled'     => __( 'Star', 'crocina-forms' ),
			'dashicons-heart'           => __( 'Heart', 'crocina-forms' ),
			'dashicons-megaphone'       => __( 'Megaphone', 'crocina-forms' ),
			'dashicons-location'        => __( 'Location', 'crocina-forms' ),
			'dashicons-calendar-alt'    => __( 'Calendar', 'crocina-forms' ),
			'dashicons-clipboard'       => __( 'Clipboard', 'crocina-forms' ),
			'dashicons-admin-links'     => __( 'Link', 'crocina-forms' ),
			'dashicons-portfolio'       => __( 'Briefcase', 'crocina-forms' ),
		);
		$current_icon   = $design['button_icon'] ?? '';
		$current_label  = $current_icon && isset( $icons[ $current_icon ] )
			? $icons[ $current_icon ]
			: __( 'No icon', 'crocina-forms' );
		?>
		<div class="crocina-design-compact">

			<!-- Section 1: Button settings -->
			<div class="crocina-design-section">
				<span class="crocina-design-section-title dashicons dashicons-edit" aria-hidden="true"></span>
				<span class="crocina-design-section-label"><?php esc_html_e( 'Button', 'crocina-forms' ); ?></span>
			</div>
			<div class="crocina-design-grid">
				<div class="crocina-design-item">
					<label for="crocina-button-text"><?php esc_html_e( 'Button text', 'crocina-forms' ); ?>
						<input type="text" id="crocina-button-text" name="crocina_button_text" value="<?php echo esc_attr( $design['button_text'] ); ?>" />
					</label>
				</div>
				<div class="crocina-design-item">
					<label><?php esc_html_e( 'Button icon', 'crocina-forms' ); ?>
						<div class="crocina-icon-picker-field">
							<button type="button" class="button crocina-icon-picker-trigger" data-empty-label="<?php esc_attr_e( 'No icon', 'crocina-forms' ); ?>">
								<span class="dashicons <?php echo esc_attr( $current_icon ?: 'dashicons-minus' ); ?>" aria-hidden="true"></span>
								<span class="crocina-icon-picker-label"><?php echo esc_html( $current_label ); ?></span>
							</button>
							<button type="button" class="button-link crocina-icon-picker-clear"><?php esc_html_e( 'Remove', 'crocina-forms' ); ?></button>
							<input type="hidden" name="crocina_button_icon" value="<?php echo esc_attr( $current_icon ); ?>" class="crocina-button-icon-input" />
						</div>
					</label>
				</div>

				<div class="crocina-design-item crocina-design-item-color">
					<label for="crocina-button-bg"><?php esc_html_e( 'Button background', 'crocina-forms' ); ?>
						<div class="crocina-color-compact">
							<input type="color" id="crocina-button-bg" name="crocina_button_background" value="<?php echo esc_attr( $design['button_background'] ?: '#0e64b7' ); ?>" class="crocina-design-color-picker" />
						</div>
					</label>
				</div>
				<div class="crocina-design-item crocina-design-item-color">
					<label for="crocina-btn-text-color"><?php esc_html_e( 'Button text color', 'crocina-forms' ); ?>
						<div class="crocina-color-compact">
							<input type="color" id="crocina-btn-text-color" name="crocina_button_text_color" value="<?php echo esc_attr( $design['button_text_color'] ?: '#ffffff' ); ?>" class="crocina-design-color-picker" />
						</div>
					</label>
				</div>

				<!-- Theme primary color toggle -->
				<div class="crocina-design-item crocina-design-item-full">
					<label class="crocina-toggle-row">
						<input type="hidden" name="crocina_use_primary_color" value="0" />
						<input type="checkbox" name="crocina_use_primary_color" value="1" <?php checked( ! empty( $design['use_primary_color'] ) ); ?> />
						<span><?php esc_html_e( 'Use theme primary color for submit button', 'crocina-forms' ); ?></span>
					</label>
				</div>
			</div>

			<!-- Section 2: Colors -->
			<div class="crocina-design-section">
				<span class="crocina-design-section-title dashicons dashicons-art" aria-hidden="true"></span>
				<span class="crocina-design-section-label"><?php esc_html_e( 'Colors', 'crocina-forms' ); ?></span>
			</div>
			<div class="crocina-design-grid">
				<div class="crocina-design-item crocina-design-item-color">
					<label for="crocina-field-label-color"><?php esc_html_e( 'Field label color', 'crocina-forms' ); ?>
						<div class="crocina-color-compact">
							<input type="color" id="crocina-field-label-color" name="crocina_field_text_color" value="<?php echo esc_attr( $design['field_text_color'] ?: '#343a40' ); ?>" class="crocina-design-color-picker" />
						</div>
					</label>
				</div>
				<div class="crocina-design-item crocina-design-item-color">
					<label for="crocina-form-bg"><?php esc_html_e( 'Form background', 'crocina-forms' ); ?>
						<div class="crocina-color-compact">
							<input type="color" id="crocina-form-bg" name="crocina_form_background" value="<?php echo esc_attr( $design['form_background'] ?: '#ffffff' ); ?>" class="crocina-design-color-picker" />
						</div>
					</label>
				</div>

				<div class="crocina-design-item crocina-design-item-color">
					<label for="crocina-field-border-color"><?php esc_html_e( 'Field border color', 'crocina-forms' ); ?>
						<div class="crocina-color-compact">
							<input type="color" id="crocina-field-border-color" name="crocina_field_border_color" value="<?php echo esc_attr( $design['field_border_color'] ?? '#d7dfe9' ); ?>" class="crocina-design-color-picker" />
						</div>
					</label>
				</div>

				<div class="crocina-design-item crocina-design-item-color">
					<label for="crocina-field-focus-color"><?php esc_html_e( 'Field focus color', 'crocina-forms' ); ?>
						<div class="crocina-color-compact">
							<input type="color" id="crocina-field-focus-color" name="crocina_field_focus_color" value="<?php echo esc_attr( $design['field_focus_color'] ?? '#0e64b7' ); ?>" class="crocina-design-color-picker" />
						</div>
					</label>
				</div>
			</div>

			<!-- Section 3: Layout & Dimensions -->
			<div class="crocina-design-section">
				<span class="crocina-design-section-title dashicons dashicons-layout" aria-hidden="true"></span>
				<span class="crocina-design-section-label"><?php esc_html_e( 'Layout &amp; Dimensions', 'crocina-forms' ); ?></span>
			</div>
			<div class="crocina-design-grid">
				<div class="crocina-design-item">
					<label for="crocina-border-radius"><?php esc_html_e( 'Border radius (px)', 'crocina-forms' ); ?>
						<input type="text" id="crocina-border-radius" name="crocina_border_radius" value="<?php echo esc_attr( $design['border_radius'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. 8', 'crocina-forms' ); ?>" />
					</label>
				</div>
				<div class="crocina-design-item">
					<label for="crocina-padding"><?php esc_html_e( 'Padding (px)', 'crocina-forms' ); ?>
						<input type="text" id="crocina-padding" name="crocina_padding" value="<?php echo esc_attr( $design['padding'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. 20', 'crocina-forms' ); ?>" />
					</label>
				</div>

				<div class="crocina-design-item">
					<label for="crocina-font-size"><?php esc_html_e( 'Font size (px)', 'crocina-forms' ); ?>
						<input type="text" id="crocina-font-size" name="crocina_font_size" value="<?php echo esc_attr( $design['font_size'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. 14', 'crocina-forms' ); ?>" />
					</label>
				</div>
				<div class="crocina-design-item">
					<label for="crocina-template-select"><?php esc_html_e( 'Default template', 'crocina-forms' ); ?>
						<div class="crocina-template-selector-wrap">
							<select name="crocina_template" id="crocina-template-select">
								<?php foreach ( $allowed_templates as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_template, $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="button" class="button crocina-browse-templates">
								<span class="dashicons dashicons-layout" aria-hidden="true"></span>
								<?php esc_html_e( 'Browse', 'crocina-forms' ); ?>
							</button>
						</div>
					</label>
				</div>
				<div class="crocina-design-item">
					<label for="crocina-theme"><?php esc_html_e( 'Default theme', 'crocina-forms' ); ?>
						<select id="crocina-theme" name="crocina_theme">
							<?php foreach ( $allowed_themes as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_theme, $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
			</div>

			<!-- Section 4: Content & Advanced -->
			<div class="crocina-design-section">
				<span class="crocina-design-section-title dashicons dashicons-editor-code" aria-hidden="true"></span>
				<span class="crocina-design-section-label"><?php esc_html_e( 'Content &amp; Advanced', 'crocina-forms' ); ?></span>
			</div>
			<div class="crocina-design-grid">
				<!-- Full-width: Form intro / description -->
				<div class="crocina-design-item crocina-design-item-full">
					<label for="crocina-form-intro"><?php esc_html_e( 'Form intro (shown above fields)', 'crocina-forms' ); ?>
						<textarea id="crocina-form-intro" name="crocina_form_intro" rows="2" placeholder="<?php esc_attr_e( 'Welcome message, instructions, etc.', 'crocina-forms' ); ?>" aria-describedby="crocina-intro-desc"><?php echo esc_textarea( $design['form_intro'] ?? '' ); ?></textarea>
						<small id="crocina-intro-desc" class="crocina-field-helper"><?php esc_html_e( 'Displayed above the form fields.', 'crocina-forms' ); ?></small>
					</label>
				</div>

				<!-- Full-width: Form footer text -->
				<div class="crocina-design-item crocina-design-item-full">
					<label for="crocina-form-footer"><?php esc_html_e( 'Form footer (shown below fields)', 'crocina-forms' ); ?>
						<textarea id="crocina-form-footer" name="crocina_form_footer" rows="2" placeholder="<?php esc_attr_e( 'Disclaimer, privacy note, extra links, etc.', 'crocina-forms' ); ?>" aria-describedby="crocina-footer-desc"><?php echo esc_textarea( $design['form_footer'] ?? '' ); ?></textarea>
						<small id="crocina-footer-desc" class="crocina-field-helper"><?php esc_html_e( 'Displayed below the form fields before the submit button.', 'crocina-forms' ); ?></small>
					</label>
				</div>

				<!-- Full-width: Extra CSS -->
				<div class="crocina-design-item crocina-design-item-full">
					<label for="crocina-custom-css"><?php esc_html_e( 'Extra CSS', 'crocina-forms' ); ?>
						<textarea id="crocina-custom-css" name="crocina_custom_css" rows="3" placeholder=".crocina-form { /* your custom styles */ }" aria-describedby="crocina-css-desc"><?php echo esc_textarea( $design['custom_css'] ); ?></textarea>
						<small id="crocina-css-desc" class="crocina-field-helper"><?php esc_html_e( 'Custom CSS for advanced styling. Only available to administrators.', 'crocina-forms' ); ?></small>
					</label>
				</div>
			</div>

			<?php /* Template thumbnail modal */ ?>
			<div class="crocina-template-modal" id="crocina-template-modal" aria-hidden="true">
				<div class="crocina-template-modal-dialog" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Choose a template', 'crocina-forms' ); ?>">
					<div class="crocina-template-modal-header">
						<strong><?php esc_html_e( 'Choose a template', 'crocina-forms' ); ?></strong>
						<p><?php esc_html_e( 'Pick the layout style that best fits your form.', 'crocina-forms' ); ?></p>
						<button type="button" class="button-link crocina-template-modal-close" aria-label="<?php esc_attr_e( 'Close', 'crocina-forms' ); ?>">×</button>
					</div>
					<div class="crocina-template-modal-grid">
						<?php
						$template_descriptions = array(
							'default'  => __( 'Clean, flat layout with minimal decoration.', 'crocina-forms' ),
							'card'     => __( 'Lifted card-style with rounded corners and subtle shadow.', 'crocina-forms' ),
							'minimal'  => __( 'Ultra-light border, airy spacing, no frills.', 'crocina-forms' ),
							'bordered' => __( 'Prominent border with a bold outline.', 'crocina-forms' ),
							'shadow'   => __( 'Floating appearance with deep shadow.', 'crocina-forms' ),
						);
						$template_thumb_classes = array(
							'default'  => 'crocina-thumb-default',
							'card'     => 'crocina-thumb-card',
							'minimal'  => 'crocina-thumb-minimal',
							'bordered' => 'crocina-thumb-bordered',
							'shadow'   => 'crocina-thumb-shadow',
						);
						foreach ( $allowed_templates as $value => $label ) :
							$is_selected = $current_template === $value;
							$desc = $template_descriptions[ $value ] ?? '';
							$thumb_class = $template_thumb_classes[ $value ] ?? '';
							?>
							<button type="button"
								class="crocina-template-thumb<?php echo $is_selected ? ' is-selected' : ''; ?>"
								data-template="<?php echo esc_attr( $value ); ?>"
								aria-pressed="<?php echo $is_selected ? 'true' : 'false'; ?>">
								<div class="crocina-thumb-visual <?php echo esc_attr( $thumb_class ); ?>">
									<div class="crocina-thumb-field">
										<span class="crocina-thumb-label"></span>
										<span class="crocina-thumb-input"></span>
									</div>
									<div class="crocina-thumb-field">
										<span class="crocina-thumb-label"></span>
										<span class="crocina-thumb-textarea"></span>
									</div>
									<span class="crocina-thumb-button"><?php esc_html_e( 'Send', 'crocina-forms' ); ?></span>
								</div>
								<div class="crocina-thumb-info">
									<strong class="crocina-thumb-name"><?php echo esc_html( $label ); ?></strong>
									<?php if ( $desc ) : ?>
										<span class="crocina-thumb-desc"><?php echo esc_html( $desc ); ?></span>
									<?php endif; ?>
								</div>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<?php /* Icon picker modal (re-used from original code) */ ?>
			<div class="crocina-icon-picker-modal" aria-hidden="true">
				<div class="crocina-icon-picker-dialog" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Icon picker', 'crocina-forms' ); ?>">
					<div class="crocina-icon-picker-header">
						<strong><?php esc_html_e( 'Choose an icon', 'crocina-forms' ); ?></strong>
						<button type="button" class="button-link crocina-icon-picker-close" aria-label="<?php esc_attr_e( 'Close', 'crocina-forms' ); ?>">×</button>
					</div>
					<div class="crocina-icon-picker-search">
						<input type="search" placeholder="<?php esc_attr_e( 'Search icons...', 'crocina-forms' ); ?>" class="crocina-icon-picker-search-input" />
					</div>
					<div class="crocina-icon-picker-grid">
						<?php foreach ( $icons as $icon => $label ) : ?>
							<button
								type="button"
								class="crocina-icon-picker-option"
								data-icon="<?php echo esc_attr( $icon ); ?>"
								data-label="<?php echo esc_attr( $label ); ?>"
							>
								<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
								<span class="crocina-icon-picker-name"><?php echo esc_html( $label ); ?></span>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the notifications meta box.
	 */
	public function render_notifications_meta_box( $post ) {
		$options = get_post_meta( $post->ID, 'crocina_alert_options', true );
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		?>
		<div class="crocina-notifications-guidance">
			<h4><?php esc_html_e( 'Notifications & Webhooks', 'crocina-forms' ); ?></h4>
			<p><?php esc_html_e( 'Configure how this form notifies you — via email, instant-message channels, or custom webhook URLs. Only the channels you enable below will be used.', 'crocina-forms' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'Subject example: {form_title} received on {page_title}', 'crocina-forms' ); ?></li>
				<li><?php esc_html_e( 'Message example: {fields} sent from {page_url}', 'crocina-forms' ); ?></li>
			</ul>
		</div>
		<div class="crocina-notifications-sample">
			<pre><?php echo esc_html( '{' . "\n" . '  "form_id": "{form_id}",' . "\n" . '  "page_title": "{page_title}",' . "\n" . '  "page_url": "{page_url}",' . "\n" . '  "submitted_at": "{submitted_at}",' . "\n" . '  "submitted_at_jalali": "{submitted_at_jalali}",' . "\n" . '  "fields": "{fields}"' . "\n" . '}' ); ?></pre>
		</div>
		<div class="crocina-notifications-grid">
			<label>
				<span><?php esc_html_e( 'Webhook endpoints (one per line)', 'crocina-forms' ); ?></span>
				<textarea name="crocina_webhook_endpoints" rows="4" placeholder="https://example.com/webhook&#10;https://hooks.example.com/notify"><?php echo esc_textarea( $options['webhook_endpoints'] ?? '' ); ?></textarea>
				<details class="crocina-webhook-guide">
					<summary><?php esc_html_e( 'How to set up webhook endpoints', 'crocina-forms' ); ?></summary>
					<div class="crocina-webhook-guide-body">
						<p><?php esc_html_e( 'Webhooks send a JSON POST request to each endpoint URL whenever this form is submitted.', 'crocina-forms' ); ?></p>
						<ol>
							<li><strong><?php esc_html_e( 'Get the endpoint URL', 'crocina-forms' ); ?>:</strong> <?php esc_html_e( 'Create a server endpoint (PHP, Node.js, Zapier, Make, n8n, etc.) that accepts POST requests.', 'crocina-forms' ); ?></li>
							<li><strong><?php esc_html_e( 'Paste the full URL', 'crocina-forms' ); ?>:</strong> <?php esc_html_e( 'Enter one URL per line in the field above (e.g.', 'crocina-forms' ); ?> <code>https://hooks.example.com/crocina</code>).</li>
							<li><strong><?php esc_html_e( 'Receive the payload', 'crocina-forms' ); ?>:</strong> <?php esc_html_e( 'Each endpoint receives a JSON payload containing', 'crocina-forms' ); ?> <code>form_id</code>, <code>fields</code>, <code>page_title</code>, <code>page_url</code>, <code>submitted_at</code>, <code>user_ip</code>.</li>
							<li><strong><?php esc_html_e( 'Authentication', 'crocina-forms' ); ?>:</strong> <?php esc_html_e( 'To protect your endpoint, add a secret query parameter (e.g.', 'crocina-forms' ); ?> <code>?secret=YOUR_TOKEN</code>) <?php esc_html_e( 'and validate it on your server.', 'crocina-forms' ); ?></li>
							<li><strong><?php esc_html_e( 'HTTPS required', 'crocina-forms' ); ?>:</strong> <?php esc_html_e( 'Only HTTPS endpoints are allowed by default for security.', 'crocina-forms' ); ?></li>
						</ol>
						<p><strong><?php esc_html_e( 'Example PHP endpoint', 'crocina-forms' ); ?>:</strong></p>
						<pre><code>&lt;?php
$input = json_decode( file_get_contents( 'php://input' ), true );
$form_id = $input['form_id'] ?? 0;
$fields  = $input['fields'] ?? [];
// Do something with $fields...
error_log( 'Crocina form submitted: ' . $form_id );</code></pre>
					</div>
				</details>
			</label>
			<label>
				<span><?php esc_html_e( 'Message template', 'crocina-forms' ); ?></span>
				<textarea name="crocina_message_template"><?php echo esc_textarea( $options['message_template'] ?? '' ); ?></textarea>
			</label>
			<label>
				<span><?php esc_html_e( 'Subject template', 'crocina-forms' ); ?></span>
				<input type="text" name="crocina_subject_template" value="<?php echo esc_attr( $options['email_subject'] ?? '' ); ?>" />
			</label>
			<label>
				<span><?php esc_html_e( 'Email recipients (comma separated)', 'crocina-forms' ); ?></span>
				<input type="text" name="crocina_email_recipients" value="<?php echo esc_attr( $options['email_recipients'] ?? '' ); ?>" />
			</label>
		</div>
		<div class="crocina-notifications-channels">
			<label class="crocina-channel-row">
				<input type="checkbox" name="crocina_enable_email" value="1" <?php checked( ! empty( $options['enable_email'] ) ); ?> />
				<span><?php esc_html_e( 'Enable email recipients', 'crocina-forms' ); ?></span>
			</label>
			<label class="crocina-channel-row">
				<input type="checkbox" name="crocina_enable_telegram" value="1" <?php checked( ! empty( $options['enable_telegram'] ) ); ?> />
				<span><?php esc_html_e( 'Use the global Telegram settings for this form', 'crocina-forms' ); ?></span>
			</label>
			<label class="crocina-channel-row">
				<input type="checkbox" name="crocina_enable_whatsapp" value="1" <?php checked( ! empty( $options['enable_whatsapp'] ) ); ?> />
				<span><?php esc_html_e( 'Notify via WhatsApp (global endpoint/token)', 'crocina-forms' ); ?></span>
			</label>
			<label class="crocina-channel-row">
				<input type="checkbox" name="crocina_enable_bale" value="1" <?php checked( ! empty( $options['enable_bale'] ) ); ?> />
				<span><?php esc_html_e( 'Send payload to Bale endpoint/token', 'crocina-forms' ); ?></span>
			</label>
			<label class="crocina-channel-row">
				<input type="checkbox" name="crocina_enable_eitaa" value="1" <?php checked( ! empty( $options['enable_eitaa'] ) ); ?> />
				<span><?php esc_html_e( 'Send payload to Eitaa webhook', 'crocina-forms' ); ?></span>
			</label>
			<label class="crocina-channel-row">
				<input type="checkbox" name="crocina_enable_rubika" value="1" <?php checked( ! empty( $options['enable_rubika'] ) ); ?> />
				<span><?php esc_html_e( 'Send payload to Rubika endpoint', 'crocina-forms' ); ?></span>
			</label>
		</div>

		<div class="crocina-test-notification-section">
			<button type="button" class="button crocina-test-notification-btn" data-form-id="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'crocina_test_notification' ) ); ?>">
				<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
				<?php esc_html_e( 'Send test notification', 'crocina-forms' ); ?>
			</button>
			<p class="crocina-test-notification-desc"><?php esc_html_e( 'Sends a sample submission to all enabled channels to verify configuration.', 'crocina-forms' ); ?></p>
			<div class="crocina-test-notification-results" aria-live="polite"></div>
		</div>

		<label class="crocina-notifications-logging">
			<input type="hidden" name="crocina_enable_log" value="0" />
			<input type="checkbox" name="crocina_enable_log" value="1" <?php checked( isset( $options['enable_log'] ) ? $options['enable_log'] : true ); ?> />
			<?php esc_html_e( 'Log submissions for this form (stored in the inbox).', 'crocina-forms' ); ?>
		</label>
		<?php
	}

	/**
	 * Save form meta (fields, design, alerts) when the form post is saved.
	 */
	public function save_form_meta( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! $this->has_post( 'crocina_form_meta_nonce' ) || ! wp_verify_nonce( wp_unslash( $this->input_post( 'crocina_form_meta_nonce' ) ), 'crocina_form_meta' ) ) {
			return;
		}

		if ( 'crocina_form' !== $post->post_type ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( $this->has_post( 'crocina_form_slug' ) ) {
			$slug = sanitize_title( wp_unslash( $this->input_post( 'crocina_form_slug' ) ) );
			if ( $slug && $slug !== $post->post_name ) {
				remove_action( 'save_post_crocina_form', array( $this, 'save_form_meta' ), 10 );
				wp_update_post( array(
					'ID'        => $post_id,
					'post_name' => $slug,
				) );
				add_action( 'save_post_crocina_form', array( $this, 'save_form_meta' ), 10, 2 );
			}
		}

		$fields = $this->sanitize_form_fields( $this->get_post_array() );
		if ( $fields ) {
			update_post_meta( $post_id, 'crocina_fields', $fields );
		} else {
			delete_post_meta( $post_id, 'crocina_fields' );
		}

		$design = $this->sanitize_design( $this->get_post_array() );
		update_post_meta( $post_id, 'crocina_form_design', $design );

		$alerts = $this->sanitize_alert_options( $this->get_post_array() );
		update_post_meta( $post_id, 'crocina_alert_options', $alerts );
	}

	/**
	 * Render a single field card for the builder interface.
	 */
	public function render_field_card( $field = array(), $is_template = false ) {
		$type        = $field['type'] ?? 'text';
		$label       = $field['label'] ?? '';
		$slug        = $field['slug'] ?? '';
		$placeholder = $field['placeholder'] ?? '';
		$helper_text = $field['helper_text'] ?? '';
		$options     = $field['options'] ?? '';
		$required    = ! empty( $field['required'] );
		$width       = $field['column_width'] ?? '1-1';
		$allowed_widths = array(
			'1-1' => __( '1/1', 'crocina-forms' ),
			'1-2' => __( '1/2', 'crocina-forms' ),
			'1-3' => __( '1/3', 'crocina-forms' ),
		);
		if ( ! array_key_exists( $width, $allowed_widths ) ) {
			$width = '1-1';
		}
		$admin = $this->app->resolve( 'admin' );
		?>
		<div class="crocina-field-card<?php echo $is_template ? ' is-template' : ''; ?> crocina-col-<?php echo esc_attr( $width ); ?>" data-field-type="<?php echo esc_attr( $type ); ?>">
			<div class="crocina-field-card-header">
				<span class="crocina-field-card-handle dashicons dashicons-menu" aria-hidden="true"></span>
				<span class="crocina-field-card-icon dashicons <?php echo esc_attr( $admin->get_field_type_icon( $type ) ); ?>" aria-hidden="true"></span>
				<div class="crocina-field-card-meta">
					<span class="crocina-field-card-label"><?php echo esc_html( $label ?: __( 'New Field', 'crocina-forms' ) ); ?></span>
					<span class="crocina-field-card-key"><?php echo esc_html( $slug ?: __( 'auto key', 'crocina-forms' ) ); ?></span>
				</div>
				<div class="crocina-field-card-actions">
					<button type="button" class="crocina-field-toggle button-link dashicons dashicons-arrow-down-alt2" aria-label="<?php esc_attr_e( 'Toggle field options', 'crocina-forms' ); ?>"></button>
					<button type="button" class="crocina-remove-field button-link dashicons dashicons-no-alt" aria-label="<?php esc_attr_e( 'Remove field', 'crocina-forms' ); ?>"></button>
				</div>
			</div>
			<div class="crocina-field-card-body">
				<p class="crocina-field-card-subtitle"><?php esc_html_e( 'Label, slug, type, placeholder, helper text, and choice options live here. Required fields will insist on data when the form is submitted.', 'crocina-forms' ); ?></p>
				<div class="crocina-field-card-grid">
					<label>
						<span><?php esc_html_e( 'Label', 'crocina-forms' ); ?></span>
						<input type="text" name="crocina_field_label[]" value="<?php echo esc_attr( $label ); ?>" />
					</label>
					<label>
						<span><?php esc_html_e( 'Key / Slug', 'crocina-forms' ); ?></span>
						<input type="text" name="crocina_field_slug[]" value="<?php echo esc_attr( $slug ); ?>" />
					</label>
					<label>
						<span><?php esc_html_e( 'Type', 'crocina-forms' ); ?></span>
						<select name="crocina_field_type[]">
							<?php
							foreach ( $admin->get_field_types() as $value => $title ) {
								printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $type, $value, false ), esc_html( $title ) );
							}
							?>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( 'Column width', 'crocina-forms' ); ?></span>
						<select name="crocina_field_width[]">
							<?php foreach ( $allowed_widths as $value => $title ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $width, $value ); ?>><?php echo esc_html( $title ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( 'Placeholder', 'crocina-forms' ); ?></span>
						<input type="text" name="crocina_field_placeholder[]" value="<?php echo esc_attr( $placeholder ); ?>" />
					</label>
					<label>
						<span><?php esc_html_e( 'Helper text', 'crocina-forms' ); ?></span>
						<input type="text" name="crocina_field_helper[]" value="<?php echo esc_attr( $helper_text ); ?>" />
					</label>
					<label class="crocina-field-required">
						<span><?php esc_html_e( 'Required', 'crocina-forms' ); ?></span>
						<div class="crocina-field-required-toggle">
							<input
								type="checkbox"
								class="crocina-field-required-checkbox"
								aria-label="<?php esc_attr_e( 'Mark field as required', 'crocina-forms' ); ?>"
								<?php checked( $required ); ?>
							/>
						</div>
						<input
							type="hidden"
							name="crocina_field_required[]"
							class="crocina-field-required-value"
							value="<?php echo esc_attr( $required ? '1' : '0' ); ?>"
						/>
					</label>
				</div>
				<div class="crocina-field-options">
					<div class="crocina-field-options-header">
						<strong><?php esc_html_e( 'Select / radio / checkbox options', 'crocina-forms' ); ?></strong>
						<p><?php esc_html_e( 'Add option values once using the builder below; they sync automatically for choice fields.', 'crocina-forms' ); ?></p>
					</div>
					<div class="crocina-options-builder">
						<div class="crocina-options-list"></div>
						<button type="button" class="crocina-option-add button-link"><?php esc_html_e( 'Add option', 'crocina-forms' ); ?></button>
						<input type="hidden" class="crocina-field-options-hidden" name="crocina_field_options[]" value="<?php echo esc_attr( $options ); ?>" />
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Sanitize form fields from POST input.
	 */
	public function sanitize_form_fields( $input ) {
		$labels       = is_array( $input['crocina_field_label'] ?? null ) ? $input['crocina_field_label'] : array();
		$slugs        = is_array( $input['crocina_field_slug'] ?? null ) ? $input['crocina_field_slug'] : array();
		$types        = is_array( $input['crocina_field_type'] ?? null ) ? $input['crocina_field_type'] : array();
		$placeholders = is_array( $input['crocina_field_placeholder'] ?? null ) ? $input['crocina_field_placeholder'] : array();
		$helpers      = is_array( $input['crocina_field_helper'] ?? null ) ? $input['crocina_field_helper'] : array();
		$options_list = is_array( $input['crocina_field_options'] ?? null ) ? $input['crocina_field_options'] : array();
		$required     = is_array( $input['crocina_field_required'] ?? null ) ? $input['crocina_field_required'] : array();
		$widths       = is_array( $input['crocina_field_width'] ?? null ) ? $input['crocina_field_width'] : array();
		$allowed_widths = array( '1-1', '1-2', '1-3' );

		$fields = array();
		$seen_slugs = array();
		$indices = array_unique( array_merge(
			array_keys( $labels ),
			array_keys( $slugs ),
			array_keys( $types ),
			array_keys( $placeholders ),
			array_keys( $helpers ),
			array_keys( $options_list ),
			array_keys( $required ),
			array_keys( $widths )
		) );

		$render = $this->app->resolve( 'render' );
		$admin  = $this->app->resolve( 'admin' );

		foreach ( $indices as $index ) {
			$label_raw = wp_unslash( $labels[ $index ] ?? '' );
			$label = wp_kses( $label_raw, $render->get_label_allowed_tags() );
			if ( ! $label && empty( $slugs[ $index ] ) ) {
				continue;
			}

			$slug_source = wp_unslash( $slugs[ $index ] ?? $label );
			$slug = sanitize_key( $slug_source );
			$slug = preg_replace( '/[^a-z0-9_]/', '_', $slug );
			$slug = preg_replace( '/_+/', '_', $slug );
			$slug = trim( $slug, '_' );
			if ( ! $slug ) {
				$slug = 'field_' . ( $index + 1 );
			}
			$base_slug = $slug;
			$counter = 2;
			while ( in_array( $slug, $seen_slugs, true ) ) {
				$slug = $base_slug . '_' . $counter;
				$counter++;
			}
			$seen_slugs[] = $slug;

			$type = sanitize_key( wp_unslash( $types[ $index ] ?? 'text' ) );
			if ( ! array_key_exists( $type, $admin->get_field_types() ) ) {
				$type = 'text';
			}

			$width = sanitize_key( wp_unslash( $widths[ $index ] ?? '1-1' ) );
			if ( ! in_array( $width, $allowed_widths, true ) ) {
				$width = '1-1';
			}

			$fields[] = array(
				'label'       => $label,
				'slug'        => $slug,
				'type'        => $type,
				'placeholder' => sanitize_text_field( wp_unslash( $placeholders[ $index ] ?? '' ) ),
				'helper_text' => sanitize_text_field( wp_unslash( $helpers[ $index ] ?? '' ) ),
				'options'     => sanitize_text_field( wp_unslash( $options_list[ $index ] ?? '' ) ),
				'required'    => ! empty( $required[ $index ] ) ? 1 : 0,
				'column_width'=> $width,
			);
		}

		return $fields;
	}

	/**
	 * Sanitize design settings from POST input.
	 */
	public function sanitize_design( $input ) {
		$custom_css = $input['crocina_custom_css'] ?? '';
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$custom_css = '';
		}
		$custom_css = $this->sanitize_custom_css( $custom_css );

		$allowed_themes = array( 'modern', 'classic', 'minimal' );
		$theme = sanitize_key( $input['crocina_theme'] ?? 'modern' );
		if ( ! in_array( $theme, $allowed_themes, true ) ) {
			$theme = 'modern';
		}

		$allowed_templates = array( 'default', 'card', 'minimal', 'bordered', 'shadow' );
		$template = sanitize_key( $input['crocina_template'] ?? 'default' );
		if ( ! in_array( $template, $allowed_templates, true ) ) {
			$template = 'default';
		}

		$allowed_tags = array(
			'p' => array(), 'br' => array(), 'strong' => array(), 'em' => array(),
			'a' => array( 'href' => true, 'title' => true, 'rel' => true, 'target' => true ),
		);

		return array(
			'button_text'        => sanitize_text_field( $input['crocina_button_text'] ?? '' ),
			'button_icon'        => sanitize_text_field( $input['crocina_button_icon'] ?? '' ),
			'button_background'  => sanitize_hex_color( $input['crocina_button_background'] ?? '' ),
			'button_text_color'  => sanitize_hex_color( $input['crocina_button_text_color'] ?? '' ),
			'form_background'    => sanitize_hex_color( $input['crocina_form_background'] ?? '' ),
			'field_text_color'   => sanitize_hex_color( $input['crocina_field_text_color'] ?? '' ),
			'custom_css'         => $custom_css,
			'border_radius'      => sanitize_text_field( $input['crocina_border_radius'] ?? '' ),
			'padding'            => sanitize_text_field( $input['crocina_padding'] ?? '' ),
			'font_size'          => sanitize_text_field( $input['crocina_font_size'] ?? '' ),
			'field_border_color' => sanitize_hex_color( $input['crocina_field_border_color'] ?? '' ),
			'field_focus_color'  => sanitize_hex_color( $input['crocina_field_focus_color'] ?? '' ),
			'template'           => $template,
			'theme'              => $theme,
			'use_primary_color'  => ! empty( $input['crocina_use_primary_color'] ) ? 1 : 0,
			'form_intro'         => wp_kses( $input['crocina_form_intro'] ?? '', $allowed_tags ),
			'form_footer'        => wp_kses( $input['crocina_form_footer'] ?? '', $allowed_tags ),
		);
	}

	/**
	 * Sanitize a block of custom CSS.
	 */
	public function sanitize_custom_css( $value ) {
		$value = is_string( $value ) ? wp_unslash( $value ) : '';
		$value = wp_kses_no_null( $value );
		$value = wp_strip_all_tags( $value );
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( current_user_can( 'unfiltered_html' ) ) {
			return $value;
		}

		$safe_blocks = array();
		if ( false !== strpos( $value, '{' ) ) {
			if ( preg_match_all( '/([^{}]+)\\{([^{}]*)\\}/', $value, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$selector     = trim( preg_replace( '/[^a-zA-Z0-9 ,.:#_\-\[\]=\"\'>+~()]/', '', $match[1] ) );
					$declarations = safecss_filter_attr( trim( $match[2] ) );
					if ( '' !== $selector && '' !== $declarations ) {
						$safe_blocks[] = $selector . ' { ' . $declarations . ' }';
					}
				}
			}
			return implode( "\n", $safe_blocks );
		}

		return safecss_filter_attr( $value );
	}

	/**
	 * Sanitize alert/notification options from POST input.
	 */
	public function sanitize_alert_options( $input ) {
		return array(
			'webhook_endpoints' => sanitize_textarea_field( $input['crocina_webhook_endpoints'] ?? '' ),
			'message_template'  => sanitize_textarea_field( $input['crocina_message_template'] ?? '' ),
			'email_subject'     => sanitize_text_field( $input['crocina_subject_template'] ?? '' ),
			'email_recipients'  => sanitize_text_field( $input['crocina_email_recipients'] ?? '' ),
			'enable_email'      => isset( $input['crocina_enable_email'] ) ? 1 : 0,
			'enable_telegram'   => isset( $input['crocina_enable_telegram'] ) ? 1 : 0,
			'enable_whatsapp'   => isset( $input['crocina_enable_whatsapp'] ) ? 1 : 0,
			'enable_bale'       => isset( $input['crocina_enable_bale'] ) ? 1 : 0,
			'enable_eitaa'      => isset( $input['crocina_enable_eitaa'] ) ? 1 : 0,
			'enable_rubika'     => isset( $input['crocina_enable_rubika'] ) ? 1 : 0,
			'enable_log'        => isset( $input['crocina_enable_log'] ) ? 1 : 0,
		);
	}
}
