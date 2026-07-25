<?php

defined( 'ABSPATH' ) || exit;

class Crocina_Notifications {

	/** @var bool */
	private $abort_retry = false;

	/** @var Crocina_App */
	private $app;

	/** @var string|null Cached path to events.log */
	private $log_file = null;

	const CRON_HOOK = 'crocina_dispatch_notifications';

	/**
	 * @param Crocina_App $app
	 */
	public function __construct( Crocina_App $app ) {
		$this->app = $app;
	}

	public function init() {
		add_action( self::CRON_HOOK, array( $this, 'dispatch' ), 10, 2 );
	}

	public function dispatch_async( $form_id, $payload ) {
		$form_id = absint( $form_id );

		if ( apply_filters( 'crocina_disable_async_notifications', defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, $form_id, $payload ) ) {
			$this->dispatch( $form_id, $payload );
			return;
		}

		$scheduled = wp_schedule_single_event( time(), self::CRON_HOOK, array( $form_id, $payload ) );
		if ( false === $scheduled ) {
			$this->dispatch( $form_id, $payload );
		}
	}

	public function dispatch( $form_id, $payload ) {
		/** @var Crocina_Forms_Core $core */
		$core = $this->app->get( 'core' );
		if ( ! $core ) {
			$this->log_error( 'Missing Crocina_Forms_Core dependency.' );
			return array();
		}

		$form_id = absint( $form_id );
		$form    = get_post( $form_id );
		$options = get_post_meta( $form_id, 'crocina_alert_options', true );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$payload   = apply_filters( 'crocina_before_notification_dispatch', $payload, $form_id );
		do_action( 'crocina_before_notifications', $form_id, $payload );

		$template     = $options['message_template'] ?? '';
		$message      = $this->prepare_message( $form, $payload, $template );
		$replacements = $this->build_replacements( $form, $payload );

		$subject_template = $options['email_subject'] ?? '{form_title} - new message';
		$subject          = $this->apply_placeholders( $subject_template, $replacements );

		$results = array( 'email' => false, 'webhooks' => array() );

		$global_settings = $core->get_global_settings();

		// --- Email channel ---
		if ( ! empty( $options['enable_email'] ) ) {
			$recipients = array_filter( array_map( 'sanitize_email', explode( ',', $options['email_recipients'] ?? '' ) ) );
			if ( empty( $recipients ) && ! empty( $global_settings['admin_email'] ) ) {
				$recipients = array_filter( array_map( 'sanitize_email', explode( ',', $global_settings['admin_email'] ) ) );
			}
			$recipients = array_slice( $recipients, 0, 20 );
			if ( $recipients ) {
				$email_channel = Crocina_Channel_Registry::get( 'email' );
				if ( $email_channel ) {
					$result = $this->send_with_retry( function() use ( $email_channel, $message, $recipients, $subject, $payload, $form_id, $form, $replacements ) {
						return $email_channel->send( $message, array(
							'recipients' => implode( ',', $recipients ),
							'subject'    => $subject,
						), array(
							'form_id'      => $form_id,
							'form'         => $form,
							'payload'      => $payload,
							'replacements' => $replacements,
							'attachments'  => array(),
						) );
					} );
					$results['email'] = $result;
					if ( ! $result ) {
						$this->log_channel_error( 'email', 'Send failed after retries', array( 'form_id' => $form_id ) );
					}
				}
			} else {
				$this->log_channel_error( 'email', 'No recipients configured.', array( 'form_id' => $form_id ) );
			}
		}

		// --- Attachments email ---
		$attachments = apply_filters( 'crocina_notification_attachments', $payload['attachments'] ?? array(), $form_id, $payload );
		$attachment_paths = $this->extract_attachment_paths( $attachments );
		if ( ! empty( $global_settings['attachments_enabled'] ) && $attachment_paths ) {
			$admin_recipients = array_filter( array_map( 'sanitize_email', explode( ',', $global_settings['admin_email'] ?? '' ) ) );
			if ( $admin_recipients ) {
				$email_channel = Crocina_Channel_Registry::get( 'email' );
				if ( $email_channel ) {
					$result = $this->send_with_retry( function() use ( $email_channel, $message, $admin_recipients, $subject, $payload, $form_id, $form, $replacements, $attachment_paths ) {
						return $email_channel->send( $message, array(
							'recipients' => implode( ',', $admin_recipients ),
							'subject'    => $subject,
						), array(
							'form_id'      => $form_id,
							'form'         => $form,
							'payload'      => $payload,
							'replacements' => $replacements,
							'attachments'  => $attachment_paths,
						) );
					} );
					$results['email_admin_attachments'] = $result;
					if ( ! $result ) {
						$this->log_channel_error( 'email', 'Attachments send failed after retries', array( 'form_id' => $form_id ) );
					}
				}
			} else {
				$this->log_channel_error( 'email', 'Attachments enabled but admin email is empty.' );
			}
		}

		// --- Generic webhooks ---
		$webhook_endpoints = $this->parse_multiline_text( $options['webhook_endpoints'] ?? '' );
		$webhook_channel   = Crocina_Channel_Registry::get( 'webhook' );
		if ( $webhook_channel ) {
			foreach ( $webhook_endpoints as $endpoint ) {
				$result = $this->send_with_retry( function() use ( $webhook_channel, $endpoint, $message, $payload, $form_id, $form, $replacements ) {
					return $webhook_channel->send( $message, array( 'endpoint' => $endpoint ), array(
						'form_id'      => $form_id,
						'form'         => $form,
						'payload'      => $payload,
						'replacements' => $replacements,
					) );
				} );
				$results['webhooks'][ $endpoint ] = $result;
				if ( ! $result ) {
					$this->log_channel_error( 'webhook', 'Send failed after retries', array(
						'form_id'  => $form_id,
						'endpoint' => substr( $endpoint, 0, 100 ),
					) );
				}
			}
		}

		// --- IM channels ---
		$channel_keys = array( 'telegram', 'bale', 'eitaa', 'rubika', 'whatsapp' );
		foreach ( $channel_keys as $key ) {
			if ( empty( $options[ 'enable_' . $key ] ) ) {
				continue;
			}

			$channel = Crocina_Channel_Registry::get( $key );
			if ( ! $channel ) {
				continue;
			}

			$token    = $global_settings[ $key . '_token' ] ?? '';
			$chat_id  = $global_settings[ $key . '_chat' ] ?? '';
			$endpoint = $global_settings[ $key . '_endpoint' ] ?? '';

			if ( empty( $token ) ) {
				$this->log_channel_error( $key, 'Token is empty while channel is enabled.', array( 'form_id' => $form_id ) );
				continue;
			}
			if ( empty( $chat_id ) ) {
				$this->log_channel_error( $key, 'Chat ID is empty while channel is enabled.', array( 'form_id' => $form_id ) );
				continue;
			}

			$result = $this->send_with_retry( function() use ( $channel, $message, $token, $chat_id, $endpoint, $form_id, $form, $payload, $replacements ) {
				return $channel->send( $message, array(
					'token'    => $token,
					'chat_id'  => $chat_id,
					'endpoint' => $endpoint,
				), array(
					'form_id'      => $form_id,
					'form'         => $form,
					'payload'      => $payload,
					'replacements' => $replacements,
				) );
			} );
			$results[ $key ] = $result;
			if ( ! $result ) {
				$this->log_channel_error( $key, 'Send failed after retries.', array( 'form_id' => $form_id ) );
			}
		}

		do_action( 'crocina_after_notifications', $form_id, $payload, $results );
		return $results;
	}

	/**
	 * Log a structured channel error to events.log.
	 *
	 * Writes a JSON line in the same format as Crocina_Event_Logger:
	 *   [timestamp] channel.error {channel, message, context, origin}
	 *
	 * This works independently of WP_DEBUG and writes directly to the
	 * plugin's events.log file inside wp-content/uploads/crocina-forms/.
	 *
	 * @param string $channel Channel key (telegram, bale, eitaa, etc.).
	 * @param string $message Human-readable error description.
	 * @param array  $context Optional extra data (form_id, endpoint, etc.).
	 * @return void
	 */
	public function log_channel_error( $channel, $message, $context = array(), $origin = "dispatch" ) {
		$log_file = $this->get_log_file_path();
		if ( ! $log_file ) {
			return;
		}

		$time = gmdate( 'Y-m-d H:i:s' );
		$entry = array(
			'channel' => sanitize_key( $channel ),
			'message' => $message,
			'context' => $context,
			'origin'  => $origin,
		);

		$line = sprintf(
			"[%s] channel.error %s\n",
			$time,
			wp_json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line, 3, $log_file );
	}

	/**
	 * Log a channel error from a test (manual) send attempt.
	 *
	 * Same as log_channel_error() but marks origin as 'test'.
	 *
	 * @param string $channel Channel key.
	 * @param string $message Error description.
	 * @param array  $context Optional extra data.
	 * @return void
	 */
	public function get_channel_error_log( $limit = 50, $filter = '' ) {
		$log_file = $this->get_log_file_path();
		if ( ! $log_file || ! is_file( $log_file ) || ! is_readable( $log_file ) ) {
			return array();
		}

		$lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( false === $lines ) {
			return array();
		}

		$errors = array();
		$limit  = max( 1, min( 200, absint( $limit ) ) );

		foreach ( array_reverse( $lines ) as $line ) {
			if ( preg_match( '/^\[([^\]]+)\]\s+channel\.error\s+(.+)$/', $line, $m ) ) {
				$data = json_decode( $m[2], true );
				if ( ! is_array( $data ) || empty( $data['channel'] ) ) {
					continue;
				}
				if ( $filter && sanitize_key( $filter ) !== $data['channel'] ) {
					continue;
				}
				$errors[] = array(
					'timestamp' => $m[1],
					'channel'   => $data['channel'],
					'message'   => $data['message'] ?? '',
					'context'   => $data['context'] ?? array(),
					'origin'    => $data['origin'] ?? 'unknown',
				);
				if ( count( $errors ) >= $limit ) {
					break;
				}
			}
		}

		return $errors;
	}

	public function build_replacements( $form, $payload ) {
		return array(
			'{form_title}'           => $form ? $form->post_title : '',
			'{page_title}'           => $payload['page_title'] ?? '',
			'{page_url}'             => $payload['page_url'] ?? '',
			'{submitted_at}'         => $payload['submitted_at'] ?? '',
			'{submitted_at_jalali}'  => $payload['submitted_at_jalali'] ?? '',
			'{user_ip}'              => $payload['user_ip'] ?? '',
			'{fields}'               => $this->render_fields_summary( $payload['fields'] ?? array() ),
		);
	}

	public function apply_placeholders( $template, $replacements ) {
		if ( empty( $template ) ) {
			$form_title = $replacements['{form_title}'] ?? '';
			if ( $form_title ) {
				return $form_title . ' ' . __( 'submission', 'crocina-forms' );
			}
			return __( 'Form submission', 'crocina-forms' );
		}
		$result = strtr( $template, $replacements );
		$result = preg_replace( '/\{[^}]+\}/', '', $result );
		return trim( $result );
	}

	/* ------------------------------------------------------------------ */
	/*  Public API for channel classes                                     */
	/* ------------------------------------------------------------------ */

	public function send_with_retry( $callback, $max_retries = 2, $delay_seconds = 0.2 ) {
		$attempt = 0;
		while ( $attempt <= $max_retries ) {
			$this->abort_retry = false;
			$result = call_user_func( $callback );
			if ( $result ) {
				return true;
			}
			if ( $this->abort_retry ) {
				break;
			}
			if ( $attempt < $max_retries ) {
				usleep( (int) ( $delay_seconds * 1000000 * ( $attempt + 1 ) ) );
			}
			$attempt++;
		}
		$this->abort_retry = false;
		return false;
	}

	public function extract_attachment_paths( $attachments ) {
		$paths = array();
		$upload_dir = wp_get_upload_dir();
		$allowed_base = wp_normalize_path( $upload_dir['basedir'] ?? '' );
		$allowed_extensions = array( 'jpg', 'jpeg', 'png', 'pdf', 'gif', 'doc', 'docx' );
		foreach ( (array) $attachments as $attachment ) {
			if ( is_array( $attachment ) && ! empty( $attachment['path'] ) ) {
				$path = $attachment['path'];
			} else {
				$path = $attachment;
			}
			$real = $path ? realpath( (string) $path ) : false;
			if ( ! $real ) {
				continue;
			}
			$real = wp_normalize_path( $real );
			if ( $allowed_base && 0 !== strpos( $real, $allowed_base ) ) {
				continue;
			}
			if ( file_exists( $real ) && is_readable( $real ) ) {
				$extension = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
				if ( '' === $extension || ! in_array( $extension, $allowed_extensions, true ) ) {
					continue;
				}
				$paths[] = $real;
			}
		}
		return array_values( array_unique( $paths ) );
	}

	public static function is_endpoint_allowed( $endpoint, $channel = 'webhook' ) {
		return self::is_allowed_endpoint_static( $endpoint, $channel );
	}

	/* ------------------------------------------------------------------ */
	/*  Private helpers                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Get or lazily initialise the path to events.log.
	 *
	 * @return string|null
	 */
	private function get_log_file_path() {
		if ( null !== $this->log_file ) {
			return $this->log_file;
		}

		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			$this->log_file = '';
			return null;
		}

		$log_dir = $uploads['basedir'] . '/crocina-forms';
		if ( ! is_dir( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		if ( ! is_dir( $log_dir ) ) {
			$this->log_file = '';
			return null;
		}

		$this->log_file = $log_dir . '/events.log';
		return $this->log_file;
	}

	private function render_fields_summary( $fields ) {
		$lines = array();
		foreach ( $fields as $field ) {
			$lines[] = sprintf( '%s: %s', $field['label'], $field['value'] );
		}
		return implode( "\n", $lines );
	}

	private function prepare_message( $form, $payload, $template ) {
		$replacements = $this->build_replacements( $form, $payload );
		if ( $template ) {
			$message = $this->apply_placeholders( $template, $replacements );
			/** @var Crocina_Forms_Core $core */
			$core = $this->app->get( 'core' );
			$settings = $core ? $core->get_global_settings() : array();
			$auto_append = ! empty( $settings['auto_append_jalali'] );
			if ( $auto_append && false === strpos( $template, '{submitted_at}' ) && false === strpos( $template, '{submitted_at_jalali}' ) ) {
				$mode = $settings['auto_append_datetime_mode'] ?? 'jalali';
				if ( 'gregorian' === $mode ) {
					$message .= "\n" . $this->apply_placeholders( __( 'Submitted at:', 'crocina-forms' ) . ' {submitted_at}', $replacements );
				} elseif ( 'both' === $mode ) {
					$message .= "\n" . $this->apply_placeholders( __( 'Submitted at:', 'crocina-forms' ) . ' {submitted_at}', $replacements );
					$message .= "\n" . $this->apply_placeholders( __( 'Submitted at (Jalali):', 'crocina-forms' ) . ' {submitted_at_jalali}', $replacements );
				} else {
					$message .= "\n" . $this->apply_placeholders( __( 'Submitted at (Jalali):', 'crocina-forms' ) . ' {submitted_at_jalali}', $replacements );
				}
			}
			return $message;
		}

		$default = array(
			__( 'Form:', 'crocina-forms' ) . ' {form_title}',
			__( 'Page:', 'crocina-forms' ) . ' {page_title} ({page_url})',
			__( 'IP:', 'crocina-forms' ) . ' {user_ip}',
			__( 'Submitted at:', 'crocina-forms' ) . ' {submitted_at}',
			__( 'Submitted at (Jalali):', 'crocina-forms' ) . ' {submitted_at_jalali}',
			__( 'Fields:', 'crocina-forms' ) . "\n{fields}",
		);

		return implode( "\n", array_map( function ( $line ) use ( $replacements ) {
			return $this->apply_placeholders( $line, $replacements );
		}, $default ) );
	}

	private static function is_allowed_endpoint_static( $endpoint, $channel ) {
		$scheme = strtolower( (string) wp_parse_url( $endpoint, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'https' ), true ) ) {
			$allow_http = apply_filters( 'crocina_allow_http_webhooks', false, $channel, $endpoint );
			if ( ! $allow_http ) {
				return false;
			}
		}

		$host = wp_parse_url( $endpoint, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}

		$disallow_private = apply_filters( 'crocina_disallow_private_webhook_hosts', true, $host, $channel, $endpoint );
		if ( $disallow_private && self::host_resolves_to_private_ip( $host ) ) {
			return false;
		}

		$defaults = array();
		if ( 'telegram' === $channel ) {
			$defaults = array( 'api.telegram.org' );
		} elseif ( in_array( $channel, array( 'bale', 'rubika', 'eitaa' ), true ) ) {
			$defaults = array( 'api.bale.ai', 'tapi.bale.ai', 'rubika.ir', 'botapi.rubika.ir', 'eitaa.com', 'api.eitaa.com', 'eitaayar.ir', 'api.eitaayar.ir' );
		}

		$allowed = apply_filters( 'crocina_allowed_webhook_domains', $defaults, $channel, $endpoint );
		if ( empty( $allowed ) ) {
			return true;
		}

		return in_array( strtolower( $host ), array_map( 'strtolower', (array) $allowed ), true );
	}

	private static function host_resolves_to_private_ip( $host ) {
		$host = strtolower( trim( (string) $host, "[]" ) );
		if ( '' === $host ) {
			return true;
		}
		if ( in_array( $host, array( 'localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback' ), true ) ) {
			return true;
		}
		$candidate_ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$candidate_ips[] = $host;
		} else {
			if ( function_exists( 'dns_get_record' ) ) {
				$records = @dns_get_record( $host, DNS_A | DNS_AAAA );
				if ( is_array( $records ) ) {
					foreach ( $records as $record ) {
						if ( ! empty( $record['ip'] ) ) {
							$candidate_ips[] = $record['ip'];
						}
						if ( ! empty( $record['ipv6'] ) ) {
							$candidate_ips[] = $record['ipv6'];
						}
					}
				}
			}
			if ( empty( $candidate_ips ) && function_exists( 'gethostbynamel' ) ) {
				$ipv4 = gethostbynamel( $host );
				if ( is_array( $ipv4 ) ) {
					$candidate_ips = array_merge( $candidate_ips, $ipv4 );
				}
			}
			if ( empty( $candidate_ips ) ) {
				return true;
			}
		}
		foreach ( array_unique( $candidate_ips ) as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return true;
			}
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return true;
			}
		}
		return false;
	}

	private function parse_multiline_text( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		$lines = array_map( 'trim', $lines );
		return array_filter( $lines, function( $line ) {
			return '' !== $line;
		} );
	}

	/**
	 * Legacy WP_DEBUG-only error log (kept for backward compatibility).
	 *
	 * @param string $message
	 * @param array  $context
	 * @return void
	 */
	public function log_error( $message, $context = array() ) {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		$context_str = '';
		if ( ! empty( $context ) ) {
			$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : false;
			if ( false !== $encoded ) {
				$context_str = ' Context: ' . $encoded;
			} else {
				$context_str = ' Context: ' . print_r( $context, true );
			}
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Crocina Notifications: ' . $message . $context_str );
		}
	}
}
