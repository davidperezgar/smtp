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
		$subject    = isset( $mail_data['subject'] ) && is_string( $mail_data['subject'] ) ? $mail_data['subject'] : '';

		$this->repository->insert(
			array(
				'recipient' => sanitize_text_field( implode( ', ', array_filter( array_map( 'strval', $recipients ) ) ) ),
				'subject'   => sanitize_text_field( $subject ),
				'status'    => $status,
				'error'     => sanitize_text_field( $error ),
				'provider'  => $this->settings->is_configured() ? $this->settings->get_provider_slug() : 'default',
			)
		);
	}
}
