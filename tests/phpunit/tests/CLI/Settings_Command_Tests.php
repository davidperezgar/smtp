<?php
/**
 * Tests for the CLI Settings_Command class.
 *
 * @package smtp
 */

use WordPress\SMTP\CLI\Settings_Command;
use WordPress\SMTP\Credentials;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Settings;

require_once TESTS_PLUGIN_DIR . '/tests/phpunit/utils/wp-cli-stub.php';

class Settings_Command_Tests extends WP_UnitTestCase {

	/**
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * @var Settings
	 */
	private $settings;

	public function set_up() {
		parent::set_up();

		WP_CLI::reset();

		$this->credentials = new Credentials();
		$this->settings    = new Settings( new Provider_Registry(), $this->credentials );
	}

	private function run_set( array $args, string $stdin = '' ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- An in-memory stream, not a file.
		$input = fopen( 'php://memory', 'r+' );
		fwrite( $input, $stdin );
		// phpcs:enable WordPress.WP.AlternativeFunctions
		rewind( $input );

		( new Settings_Command( $this->settings, new Provider_Registry(), $input ) )->set( $args );
	}

	private function assert_set_fails( array $args, string $message, string $stdin = '' ) {
		$before = get_option( Settings::OPTION );

		try {
			$this->run_set( $args, $stdin );
			$this->fail( 'Expected the command to fail.' );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( $message, $error->getMessage() );
		}

		$this->assertSame( $before, get_option( Settings::OPTION ), 'Nothing should be saved.' );
	}

	private function add_provider_with_two_secrets() {
		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['two'] = array(
					'label'     => 'Two',
					'fields'    => array(
						'key_a' => array( 'type' => 'password' ),
						'key_b' => array( 'type' => 'password' ),
					),
					'configure' => '__return_null',
				);

				return $providers;
			}
		);
	}

	private function add_locked_provider() {
		if ( ! defined( 'SMTP_TESTS_CLI_LOCKED' ) ) {
			define( 'SMTP_TESTS_CLI_LOCKED', 'from-constant' );
		}

		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['locked'] = array(
					'label'     => 'Locked',
					'fields'    => array(
						'token' => array( 'constant' => 'SMTP_TESTS_CLI_LOCKED' ),
					),
					'configure' => '__return_null',
				);

				return $providers;
			}
		);
	}

	public function test_set_saves_the_values() {
		$this->run_set( array( 'host=smtp.example.com', 'port=2525', 'encryption=ssl', 'auth=no', 'from_name=My site' ) );

		$this->assertSame( 'smtp.example.com', $this->settings->get( 'host' ) );
		$this->assertSame( 2525, $this->settings->get( 'port' ) );
		$this->assertSame( 'ssl', $this->settings->get( 'encryption' ) );
		$this->assertFalse( $this->settings->get( 'auth' ) );
		$this->assertSame( 'My site', $this->settings->get( 'from_name' ) );
		$this->assertCount( 1, WP_CLI::$successes );
	}

	public function test_set_keeps_the_other_settings() {
		$this->run_set( array( 'host=smtp.example.com', 'port=2525', 'username=me', 'auth=no', 'force_from=yes' ) );
		$this->run_set( array( 'host=other.example.com' ) );

		$this->assertSame( 'other.example.com', $this->settings->get( 'host' ) );
		$this->assertSame( 2525, $this->settings->get( 'port' ) );
		$this->assertSame( 'me', $this->settings->get( 'username' ) );
		$this->assertFalse( $this->settings->get( 'auth' ) );
		$this->assertTrue( $this->settings->get( 'force_from' ) );
	}

	public function test_set_keeps_an_equals_sign_in_the_value() {
		$this->run_set( array( 'username=a=b' ) );

		$this->assertSame( 'a=b', $this->settings->get( 'username' ) );
	}

	public function test_set_can_clear_a_text_value() {
		$this->run_set( array( 'host=smtp.example.com', 'username=me' ) );
		$this->run_set( array( 'username=' ) );

		$this->assertSame( '', $this->settings->get( 'username' ) );
	}

	public function test_set_warns_while_required_settings_are_missing() {
		$this->run_set( array( 'port=2525' ) );

		$this->assertCount( 1, WP_CLI::$warnings );
	}

	public function test_set_does_not_warn_once_configured() {
		$this->run_set( array( 'host=smtp.example.com' ) );

		$this->assertSame( array(), WP_CLI::$warnings );
	}

	public function test_set_reads_the_password_from_stdin() {
		$this->run_set( array( 'password=-' ), "s3cret-value\n" );

		$this->assertSame( 's3cret-value', $this->credentials->get( 'password' ) );
		$this->assertArrayNotHasKey( 'password', get_option( Settings::OPTION ) );
	}

	public function test_set_keeps_the_password_when_it_is_not_passed() {
		$this->credentials->set( 'password', 's3cret-value' );

		$this->run_set( array( 'host=smtp.example.com' ) );

		$this->assertSame( 's3cret-value', $this->credentials->get( 'password' ) );
	}

	public function test_set_never_prints_the_password() {
		$this->run_set( array( 'password=-' ), 's3cret-value' );

		$output = wp_json_encode( array( WP_CLI::$logs, WP_CLI::$warnings, WP_CLI::$successes ) );

		$this->assertStringNotContainsString( 's3cret-value', $output );
	}

	public function test_set_rejects_a_password_as_an_argument() {
		$this->assert_set_fails( array( 'host=smtp.example.com', 'password=s3cret-value' ), 'can not be passed as arguments' );
		$this->assertSame( '', $this->credentials->get( 'password' ) );
	}

	public function test_set_rejects_an_empty_stdin() {
		$this->assert_set_fails( array( 'host=smtp.example.com', 'password=-' ), 'No password received' );
	}

	public function test_set_rejects_two_passwords_from_stdin() {
		$this->add_provider_with_two_secrets();

		$this->assert_set_fails( array( 'provider=two', 'key_a=-', 'key_b=-' ), 'Only one password', 's3cret-value' );
		$this->assertSame( '', $this->credentials->get( 'key_a' ) );
	}

	public function test_set_rejects_an_argument_without_a_value() {
		$this->assert_set_fails( array( 'host' ), 'Use key=value' );
	}

	public function test_set_rejects_an_unknown_setting() {
		$this->assert_set_fails( array( 'host=smtp.example.com', 'nope=1' ), 'Unknown setting "nope"' );
	}

	public function test_set_rejects_an_unknown_provider() {
		$this->assert_set_fails( array( 'provider=nope' ), 'Unknown provider "nope"' );
	}

	public function test_set_rejects_a_locked_setting() {
		$this->add_locked_provider();

		$this->assert_set_fails( array( 'provider=locked', 'token=other' ), 'SMTP_TESTS_CLI_LOCKED' );
	}

	/**
	 * @dataProvider data_invalid_values
	 */
	public function test_set_rejects_invalid_values( string $arg, string $key ) {
		$this->assert_set_fails( array( 'host=smtp.example.com', $arg ), 'Invalid value for "' . $key . '"' );
	}

	public function data_invalid_values(): array {
		return array(
			'select'         => array( 'encryption=starttls', 'encryption' ),
			'text as number' => array( 'port=abc', 'port' ),
			'negative'       => array( 'port=-1', 'port' ),
			'zero'           => array( 'port=0', 'port' ),
			'email'          => array( 'from_email=nope', 'from_email' ),
			'checkbox'       => array( 'auth=maybe', 'auth' ),
			'empty checkbox' => array( 'auth=', 'auth' ),
		);
	}

	public function test_set_can_clear_the_from_email() {
		$this->run_set( array( 'from_email=me@example.com' ) );
		$this->run_set( array( 'from_email=' ) );

		$this->assertSame( '', $this->settings->get( 'from_email' ) );
	}

	public function test_set_changes_the_provider_and_validates_against_its_fields() {
		$this->add_provider_with_two_secrets();

		$this->run_set( array( 'provider=two', 'key_a=-' ), 's3cret-value' );

		$this->assertSame( 'two', $this->settings->get_provider_slug() );
		$this->assertSame( 's3cret-value', $this->credentials->get( 'key_a' ) );
	}
}
