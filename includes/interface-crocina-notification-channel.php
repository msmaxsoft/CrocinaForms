<?php
/**
 * Notification Channel Interface
 *
 * Every notification channel (email, Telegram, Bale, Eitaa, Rubika,
 * WhatsApp, generic webhook) implements this interface so the dispatcher
 * can treat them polymorphically.
 *
 * @package Crocina_Forms
 */

defined( 'ABSPATH' ) || exit;

interface Crocina_Notification_Channel {

	/**
	 * Unique machine name for the channel (e.g. 'telegram', 'eitaa').
	 *
	 * Used as the key in per-form alert options (enable_{$key}) and in
	 * global settings ({$key}_token, {$key}_chat, {$key}_endpoint).
	 *
	 * @return string
	 */
	public function get_key();

	/**
	 * Human-readable label for admin UI.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Send a notification through this channel.
	 *
	 * @param string $message  The prepared message text (placeholders resolved).
	 * @param array  $config   Channel-specific configuration keys:
	 *                         - token    (string) Bot / API token.
	 *                         - chat_id  (string) Recipient identifier.
	 *                         - endpoint (string) Base URL / endpoint override.
	 * @param array  $context  Full dispatch context:
	 *                         - form_id      (int)
	 *                         - form         (WP_Post|null)
	 *                         - payload      (array) Raw submission payload.
	 *                         - replacements (array) Placeholder map.
	 *                         - attachments  (array) File paths for email.
	 *                         - subject      (string) Email subject.
	 * @return bool True on success, false on failure.
	 */
	public function send( $message, $config, $context );
}
