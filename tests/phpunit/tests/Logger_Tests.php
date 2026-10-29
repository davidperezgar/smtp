<?php
/**
 * Tests for the Logger class.
 *
 * @package smtp
 */

use WordPress\SMTP\Credentials;
use WordPress\SMTP\Log_Repository;
use WordPress\SMTP\Logger;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Settings;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;

class Logger_Tests extends WP_UnitTestCase {
	use With_Log_Table;

	/**
	 * @var Logger
	 */
	private $logger;

	public function set_up() {
		parent::set_up();

		// The wp-env site runs on localhost, which makes the default From address invalid.
		add_filter( 'wp_mail_from', array( $this, 'get_valid_from_email' ) );

		$this->create_log_table();

		$this->logger = new Logger(
			new Log_Repository(),
			new Settings( new Provider_Registry(), new Credentials() )
		);
	}

	public function get_valid_from_email() {
		return 'wordpress@example.org';
	}

	public function test_add_hooks_registers_the_log_hooks() {
		$this->logger->add_hooks();

		$this->assertSame( 10, has_action( 'wp_mail_succeeded', array( $this->logger, 'log_success' ) ) );
		$this->assertSame( 10, has_action( 'wp_mail_failed', array( $this->logger, 'log_failure' ) ) );
		$this->assertSame( 10, has_action( 'smtp_purge_logs', array( $this->logger, 'purge' ) ) );
	}

	public function test_log_success_records_a_sent_email() {
		$this->logger->log_success(
			array(
				'to'      => array( 'a@example.org', 'b@example.org' ),
				'subject' => 'Hello',
			)
		);

		$entries = $this->get_logged_entries();

		$this->assertCount( 1, $entries );
		$this->assertSame( 'a@example.org, b@example.org', $entries[0]['recipient'] );
		$this->assertSame( 'Hello', $entries[0]['subject'] );
		$this->assertSame( 'sent', $entries[0]['status'] );
		$this->assertSame( '', $entries[0]['error'] );
	}

	public function test_log_failure_records_the_error_message() {
		$this->logger->log_failure(
			new WP_Error(
				'wp_mail_failed',
				'SMTP connect() failed.',
				array(
					'to'      => array( 'a@example.org' ),
					'subject' => 'Hello',
				)
			)
		);

		$entries = $this->get_logged_entries();

		$this->assertCount( 1, $entries );
		$this->assertSame( 'failed', $entries[0]['status'] );
		$this->assertSame( 'SMTP connect() failed.', $entries[0]['error'] );
		$this->assertSame( 'a@example.org', $entries[0]['recipient'] );
	}

	public function test_log_failure_ignores_anything_but_a_wp_error() {
		$this->logger->log_failure( 'not an error' );

		$this->assertCount( 0, $this->get_logged_entries() );
	}

	public function test_log_failure_without_mail_data_still_records_the_error() {
		$this->logger->log_failure( new WP_Error( 'wp_mail_failed', 'Boom' ) );

		$entries = $this->get_logged_entries();

		$this->assertCount( 1, $entries );
		$this->assertSame( '', $entries[0]['recipient'] );
		$this->assertSame( 'Boom', $entries[0]['error'] );
	}

	public function test_log_success_tolerates_unexpected_data() {
		$this->logger->log_success( 'unexpected' );

		$this->assertCount( 1, $this->get_logged_entries() );
	}

	public function test_text_is_sanitized_before_it_is_stored() {
		$this->logger->log_success(
			array(
				'to'      => array( '<b>a@example.org</b>' ),
				'subject' => '<script>alert(1)</script>Hi',
			)
		);

		$entry = $this->get_logged_entries()[0];

		$this->assertStringNotContainsString( '<', $entry['recipient'] );
		$this->assertStringNotContainsString( '<', $entry['subject'] );
	}

	public function test_the_message_body_is_never_stored() {
		$this->logger->log_success(
			array(
				'to'          => array( 'a@example.org' ),
				'subject'     => 'Hello',
				'message'     => 'Top secret body',
				'attachments' => array( '/tmp/secret.pdf' ),
			)
		);

		$this->assertStringNotContainsString( 'Top secret body', wp_json_encode( $this->get_logged_entries() ) );
		$this->assertStringNotContainsString( 'secret.pdf', wp_json_encode( $this->get_logged_entries() ) );
	}

	public function test_logging_can_be_disabled_with_a_filter() {
		add_filter( 'smtp_log_enabled', '__return_false' );

		$this->logger->log_success( array( 'subject' => 'Hello' ) );
		$this->logger->log_failure( new WP_Error( 'wp_mail_failed', 'Boom' ) );

		$this->assertCount( 0, $this->get_logged_entries() );
	}

	public function test_log_enabled_filter_receives_the_status_and_mail_data() {
		$received = array();

		add_filter(
			'smtp_log_enabled',
			static function ( $enabled, $mail_data, $status ) use ( &$received ) {
				$received = array( $mail_data, $status );

				return $enabled;
			},
			10,
			3
		);

		$this->logger->log_success( array( 'subject' => 'Hello' ) );

		$this->assertSame( array( array( 'subject' => 'Hello' ), 'sent' ), $received );
	}

	public function test_provider_is_default_until_a_host_is_configured() {
		$this->logger->log_success( array( 'subject' => 'Hello' ) );

		$this->assertSame( 'default', $this->get_logged_entries()[0]['provider'] );
	}

	public function test_provider_is_recorded_once_configured() {
		update_option( Settings::OPTION, array( 'host' => 'smtp.example.com' ) );

		$this->logger->log_success( array( 'subject' => 'Hello' ) );

		$this->assertSame( 'smtp', $this->get_logged_entries()[0]['provider'] );
	}

	public function test_wp_mail_is_logged_through_the_plugin_hooks() {
		wp_mail( 'to@example.org', 'Integration', 'Body' );

		$entries = $this->get_logged_entries();

		$this->assertCount( 1, $entries );
		$this->assertSame( 'to@example.org', $entries[0]['recipient'] );
		$this->assertSame( 'Integration', $entries[0]['subject'] );
		$this->assertSame( 'sent', $entries[0]['status'] );
	}

	public function test_a_failed_wp_mail_is_logged_through_the_plugin_hooks() {
		do_action(
			'wp_mail_failed',
			new WP_Error(
				'wp_mail_failed',
				'Could not connect.',
				array(
					'to'      => array( 'to@example.org' ),
					'subject' => 'Integration',
				)
			)
		);

		$entries = $this->get_logged_entries();

		$this->assertCount( 1, $entries );
		$this->assertSame( 'failed', $entries[0]['status'] );
		$this->assertSame( 'Could not connect.', $entries[0]['error'] );
	}

	private function insert_old_entry() {
		global $wpdb;

		$repository = new Log_Repository();
		$repository->insert(
			array(
				'recipient' => 'old@example.org',
				'subject'   => 'Old',
				'status'    => 'sent',
				'error'     => '',
				'provider'  => 'smtp',
			)
		);

		$wpdb->update(
			$repository->get_table_name(),
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) ) ),
			array( 'subject' => 'Old' )
		);
	}

	public function test_purge_deletes_entries_older_than_thirty_days_by_default() {
		$this->insert_old_entry();
		$this->logger->log_success( array( 'subject' => 'Recent' ) );

		$this->logger->purge();

		$this->assertSame( array( 'Recent' ), wp_list_pluck( $this->get_logged_entries(), 'subject' ) );
	}

	public function test_purge_honors_the_retention_filter() {
		$this->insert_old_entry();

		add_filter(
			'smtp_log_retention_days',
			static function () {
				return 60;
			}
		);

		$this->logger->purge();

		$this->assertCount( 1, $this->get_logged_entries() );
	}

	public function test_purge_keeps_everything_when_retention_is_disabled() {
		$this->insert_old_entry();

		add_filter( 'smtp_log_retention_days', '__return_zero' );

		$this->logger->purge();

		$this->assertCount( 1, $this->get_logged_entries() );
	}
}
