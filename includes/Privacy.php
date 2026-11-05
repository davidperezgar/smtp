<?php
/**
 * Class WordPress\SMTP\Privacy
 *
 * @package smtp
 */

namespace WordPress\SMTP;

/**
 * Integrates the email log with the WordPress privacy tools.
 *
 * Log entries sent to an address are included in personal data exports and removed on
 * erasure, and the privacy policy guide explains what is logged.
 *
 * @since 1.2.0
 */
class Privacy {

	/**
	 * Exporter and eraser key.
	 *
	 * @since 1.2.0
	 */
	const KEY = 'smtp';

	/**
	 * Number of log entries handled per exporter or eraser page.
	 *
	 * @since 1.2.0
	 */
	const PER_PAGE = 100;

	/**
	 * Log repository.
	 *
	 * @since 1.2.0
	 * @var Log_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Log_Repository $repository Log repository.
	 */
	public function __construct( Log_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Registers the hooks.
	 *
	 * @since 1.2.0
	 */
	public function add_hooks() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * Registers the email log exporter.
	 *
	 * @since 1.2.0
	 *
	 * @param array $exporters Registered exporters.
	 * @return array Registered exporters.
	 */
	public function register_exporter( $exporters ) {
		$exporters[ self::KEY ] = array(
			'exporter_friendly_name' => __( 'SMTP email log', 'smtp' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the email log eraser.
	 *
	 * @since 1.2.0
	 *
	 * @param array $erasers Registered erasers.
	 * @return array Registered erasers.
	 */
	public function register_eraser( $erasers ) {
		$erasers[ self::KEY ] = array(
			'eraser_friendly_name' => __( 'SMTP email log', 'smtp' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports the log entries sent to an email address.
	 *
	 * @since 1.2.0
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, starting at 1.
	 * @return array Exported data and whether the export is done.
	 */
	public function export( $email, $page = 1 ): array {
		$page    = max( 1, (int) $page );
		$entries = $this->get_entries( (string) $email, ( $page - 1 ) * self::PER_PAGE );
		$data    = array();

		foreach ( $entries as $entry ) {
			$data[] = array(
				'group_id'    => 'smtp-email-log',
				'group_label' => __( 'Email log', 'smtp' ),
				'item_id'     => 'smtp-email-log-' . $entry['id'],
				'data'        => array(
					array(
						'name'  => __( 'Date', 'smtp' ),
						'value' => get_date_from_gmt( $entry['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
					),
					array(
						'name'  => __( 'To', 'smtp' ),
						'value' => $entry['recipient'],
					),
					array(
						'name'  => __( 'Subject', 'smtp' ),
						'value' => $entry['subject'],
					),
					array(
						'name'  => __( 'Status', 'smtp' ),
						'value' => 'sent' === $entry['status'] ? __( 'Sent', 'smtp' ) : __( 'Failed', 'smtp' ),
					),
					array(
						'name'  => __( 'Error', 'smtp' ),
						'value' => $entry['error'],
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $entries ) < self::PER_PAGE,
		);
	}

	/**
	 * Deletes the log entries sent to an email address.
	 *
	 * Every page starts from the first remaining entry, as the previous ones are gone.
	 *
	 * @since 1.2.0
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, starting at 1. Unused.
	 * @return array Eraser response.
	 */
	public function erase( $email, $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by the eraser callback signature.
		$entries = $this->get_entries( (string) $email, 0 );
		$removed = $this->repository->delete( wp_list_pluck( $entries, 'id' ) );

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $entries ) < self::PER_PAGE,
		);
	}

	/**
	 * Adds the suggested text to the privacy policy guide.
	 *
	 * @since 1.2.0
	 */
	public function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content( __( 'SMTP', 'smtp' ), wp_kses_post( wpautop( $this->get_policy_text() ) ) );
	}

	/**
	 * Returns the suggested privacy policy text.
	 *
	 * @since 1.2.0
	 *
	 * @return string Policy text.
	 */
	public function get_policy_text(): string {
		/** This filter is documented in includes/Logger.php */
		$days = (int) apply_filters( 'smtp_log_retention_days', Logger::DEFAULT_RETENTION_DAYS );

		$text = __( 'When this site sends an email, it keeps a log entry with the date, the recipient addresses (and names, when given), the subject, whether the email was sent, and the error message when it was not. The message content and attachments are not stored.', 'smtp' );

		if ( $days > 0 ) {
			$text .= "\n\n" . sprintf(
				/* translators: %d: number of days */
				_n( 'Log entries are deleted automatically after %d day.', 'Log entries are deleted automatically after %d days.', $days, 'smtp' ),
				$days
			);
		} else {
			$text .= "\n\n" . __( 'Log entries are not deleted automatically.', 'smtp' );
		}

		$text .= "\n\n" . __( 'The log is only visible to administrators. Log entries sent to an email address are included when that person requests an export of their personal data, and deleted when they request its erasure.', 'smtp' );

		return $text;
	}

	/**
	 * Returns a page of the log entries sent to an email address.
	 *
	 * @since 1.2.0
	 *
	 * @param string $email  Email address.
	 * @param int    $offset Offset.
	 * @return array[] Entries.
	 */
	private function get_entries( string $email, int $offset ): array {
		$email = trim( $email );

		if ( '' === $email || ! is_email( $email ) ) {
			return array();
		}

		return $this->repository->get_items_by_recipient( $email, self::PER_PAGE, $offset );
	}
}
