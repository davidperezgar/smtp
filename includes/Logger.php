<?php
/**
 * Class WordPress\SMTP\Logger
 *
 * @package smtp
 */

namespace WordPress\SMTP;

/**
 * Records the outcome of every email sent through `wp_mail()`.
 *
 * Only metadata is stored: the message body and attachments are never logged.
 *
 * @since 1.2.0
 */
class Logger {

	/**
	 * Default number of days log entries are kept.
	 *
	 * @since 1.2.0
	 */
	const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Log repository.
	 *
	 * @since 1.2.0
	 * @var Log_Repository
	 */
	private $repository;

	/**
	 * Settings.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Log_Repository $repository Log repository.
	 * @param Settings       $settings   Settings.
	 */
	public function __construct( Log_Repository $repository, Settings $settings ) {
		$this->repository = $repository;
		$this->settings   = $settings;
	}

	/**
	 * Registers the hooks.
	 *
	 * @since 1.2.0
	 */
	public function add_hooks() {
		add_action( 'wp_mail_succeeded', array( $this, 'log_success' ) );
		add_action( 'wp_mail_failed', array( $this, 'log_failure' ) );
		add_action( Plugin_Main::PURGE_HOOK, array( $this, 'purge' ) );
	}

	/**
	 * Logs a sent email.
	 *
	 * @since 1.2.0
	 *
	 * @param array $mail_data Email data, as passed to `wp_mail_succeeded`.
	 */
	public function log_success( $mail_data ) {
		$mail_data = is_array( $mail_data ) ? $mail_data : array();

		$this->log( 'sent', $mail_data, '' );
	}

	/**
	 * Logs a failed email.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_Error $error Error, as passed to `wp_mail_failed`.
	 */
	public function log_failure( $error ) {
		if ( ! $error instanceof \WP_Error ) {
			return;
		}

		$mail_data = $error->get_error_data();
		$mail_data = is_array( $mail_data ) ? $mail_data : array();

		$this->log( 'failed', $mail_data, $error->get_error_message() );
	}

	/**
	 * Deletes entries older than the retention period.
	 *
	 * @since 1.2.0
	 */
	public function purge() {
		/**
		 * Filters the number of days email log entries are kept.
		 *
		 * @since 1.2.0
		 *
		 * @param int $days Number of days. Default 30.
		 */
		$days = (int) apply_filters( 'smtp_log_retention_days', self::DEFAULT_RETENTION_DAYS );

		if ( $days > 0 ) {
			$this->repository->purge( $days );
		}
	}

	/**
	 * Writes a log entry.
	 *
	 * @since 1.2.0
	 *
	 * @param string $status    Either `sent` or `failed`.
	 * @param array  $mail_data Email data.
	 * @param string $error     Error message, if any.
	 */
	private function log( string $status, array $mail_data, string $error ) {
		/**
		 * Filters whether an email should be logged.
		 *
		 * @since 1.2.0
		 *
		 * @param bool   $enabled   Whether to log the email. Default true.
		 * @param array  $mail_data Email data.
		 * @param string $status    Either `sent` or `failed`.
		 */
		if ( ! apply_filters( 'smtp_log_enabled', true, $mail_data, $status ) ) {
			return;
		}

		$recipients = isset( $mail_data['to'] ) ? (array) $mail_data['to'] : array();
		$recipients = array_filter( array_map( 'strval', array_filter( $recipients, 'is_scalar' ) ) );
		$subject    = isset( $mail_data['subject'] ) && is_string( $mail_data['subject'] ) ? $mail_data['subject'] : '';

		$this->repository->insert(
			array(
				'recipient' => $this->clean_text( implode( ', ', $recipients ) ),
				'subject'   => $this->clean_text( $subject ),
				'status'    => $status,
				'error'     => $this->clean_text( $error ),
				'provider'  => $this->settings->is_configured() ? $this->settings->get_provider_slug() : 'default',
			)
		);
	}

	/**
	 * Prepares a text value for storage.
	 *
	 * Values are stored as sent and escaped on output. Stripping tags here would remove
	 * addresses written as `Name <email>` and the addresses PHPMailer quotes in its errors.
	 *
	 * @since 1.2.0
	 *
	 * @param string $text Raw text.
	 * @return string Text with invalid UTF-8, control characters and line breaks removed.
	 */
	private function clean_text( string $text ): string {
		$text = $this->scrub_utf8( $text );

		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text ) );
	}

	/**
	 * Replaces invalid UTF-8 sequences in a string.
	 *
	 * The strip mode of wp_check_invalid_utf8() relies on iconv() before WordPress 6.9,
	 * which raises a notice on invalid input with some iconv implementations.
	 *
	 * @since 1.2.0
	 *
	 * @param string $text Raw text.
	 * @return string Valid UTF-8 text.
	 */
	private function scrub_utf8( string $text ): string {
		if ( '' !== wp_check_invalid_utf8( $text ) ) {
			return $text;
		}

		if ( function_exists( 'wp_scrub_utf8' ) ) {
			return wp_scrub_utf8( $text );
		}

		if ( function_exists( 'mb_convert_encoding' ) ) {
			return (string) mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}

		return '';
	}
}
