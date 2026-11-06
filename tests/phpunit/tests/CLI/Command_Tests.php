<?php
/**
 * Tests for the CLI Command class.
 *
 * @package smtp
 */

use WordPress\SMTP\Admin\Test_Email;
use WordPress\SMTP\CLI\Command;
use WordPress\SMTP\Credentials;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Settings;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;

require_once TESTS_PLUGIN_DIR . '/tests/phpunit/utils/wp-cli-stub.php';

class Command_Tests extends WP_UnitTestCase {
	use With_Log_Table;

	/**
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * @var Command
	 */
	private $command;

	public function set_up() {
		parent::set_up();

		// The wp-env site runs on localhost, which makes the default From address invalid.
		add_filter( 'wp_mail_from', array( $this, 'get_valid_from_email' ) );

		$this->create_log_table();
		reset_phpmailer_instance();
		WP_CLI::reset();

		$this->credentials = new Credentials();
		$settings          = new Settings( new Provider_Registry(), $this->credentials );
		$this->command     = new Command( $settings, new Test_Email( $settings ) );
	}

	public function get_valid_from_email() {
		return 'wordpress@example.org';
	}

	private function get_status_rows( array $assoc_args = array() ): array {
		$this->command->status( array(), $assoc_args );

		$rows = array();
		foreach ( WP_CLI::$logs[0][1] as $row ) {
			$rows[ $row['field'] ] = $row;
		}

		return $rows;
	}

	private function write_to_connection_log( string $message ) {
		add_action(
			'wp_mail_succeeded',
			static function () use ( $message ) {
				global $phpmailer;

				call_user_func( $phpmailer->Debugoutput, $message );
			}
		);
	}

	public function test_status_reports_an_unconfigured_site() {
		$rows = $this->get_status_rows();

		$this->assertSame( 'smtp', $rows['provider']['value'] );
		$this->assertSame( 'no', $rows['configured']['value'] );
		$this->assertSame( 'default', $rows['port']['source'] );
		$this->assertSame( 'not set', $rows['password']['value'] );
	}

	public function test_status_reports_saved_values_and_their_source() {
		update_option(
			Settings::OPTION,
			array(
				'host' => 'smtp.example.com',
				'auth' => false,
			)
		);

		$rows = $this->get_status_rows();

		$this->assertSame( 'yes', $rows['configured']['value'] );
		$this->assertSame( 'smtp.example.com', $rows['host']['value'] );
		$this->assertSame( 'database', $rows['host']['source'] );
		$this->assertSame( 'no', $rows['auth']['value'] );
	}

	public function test_status_never_prints_the_password() {
		$this->credentials->set( 'password', 's3cret-value' );

		$rows = $this->get_status_rows();

		$this->assertSame( 'set', $rows['password']['value'] );
		$this->assertSame( 'database', $rows['password']['source'] );
		$this->assertStringNotContainsString( 's3cret-value', wp_json_encode( WP_CLI::$logs ) );
	}

	public function test_status_passes_the_format_on() {
		$this->command->status( array(), array( 'format' => 'json' ) );

		$this->assertSame( 'json', WP_CLI::$logs[0][0] );
		$this->assertSame( array( 'field', 'value', 'source' ), WP_CLI::$logs[0][2] );
	}

	public function test_test_sends_to_the_given_address() {
		$this->command->test( array( 'someone@example.com' ), array() );

		$this->assertSame( 'someone@example.com', tests_retrieve_phpmailer_instance()->get_recipient( 'to' )->address );
		$this->assertCount( 1, WP_CLI::$successes );
	}

	public function test_test_defaults_to_the_admin_email() {
		$this->command->test( array(), array() );

		$this->assertSame( get_option( 'admin_email' ), tests_retrieve_phpmailer_instance()->get_recipient( 'to' )->address );
	}

	public function test_test_warns_when_smtp_is_not_configured() {
		$this->command->test( array( 'someone@example.com' ), array() );

		$this->assertCount( 1, WP_CLI::$warnings );
	}

	public function test_test_does_not_warn_when_smtp_is_configured() {
		update_option( Settings::OPTION, array( 'host' => 'smtp.example.com' ) );

		$this->command->test( array( 'someone@example.com' ), array() );

		$this->assertSame( array(), WP_CLI::$warnings );
	}

	public function test_test_fails_on_an_invalid_address_without_sending() {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Please enter a valid email address.' );

		$this->command->test( array( 'not-an-email' ), array() );
	}

	public function test_test_fails_when_the_email_can_not_be_sent() {
		add_filter(
			'pre_wp_mail',
			static function () {
				do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP connect() failed.' ) );

				return false;
			}
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'SMTP connect() failed.' );

		$this->command->test( array( 'someone@example.com' ), array() );
	}

	public function test_test_prints_the_connection_log_only_when_asked() {
		$this->write_to_connection_log( 'CLIENT -> SERVER: EHLO' );

		$this->command->test( array( 'someone@example.com' ), array() );
		$this->assertSame( array(), WP_CLI::$logs );

		$this->command->test( array( 'someone@example.com' ), array( 'connection-log' => true ) );
		$this->assertSame( array( 'CLIENT -> SERVER: EHLO' ), WP_CLI::$logs );
	}

	public function test_test_removes_the_password_from_the_connection_log() {
		$this->credentials->set( 'password', 's3cret-value' );
		$this->write_to_connection_log( 'CLIENT -> SERVER: ' . base64_encode( 's3cret-value' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$this->command->test( array( 'someone@example.com' ), array( 'connection-log' => true ) );

		$this->assertSame( array( 'CLIENT -> SERVER: [redacted]' ), WP_CLI::$logs );
	}
}
