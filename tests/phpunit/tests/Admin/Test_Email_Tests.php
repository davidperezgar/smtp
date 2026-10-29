<?php
/**
 * Tests for the Test_Email class.
 *
 * @package smtp
 */

use WordPress\SMTP\Admin\Test_Email;
use WordPress\SMTP\Credentials;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Settings;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;

class Test_Email_Tests extends WP_UnitTestCase {
	use With_Log_Table;

	/**
	 * @var Test_Email
	 */
	private $test_email;

	public function set_up() {
		parent::set_up();

		// The wp-env site runs on localhost, which makes the default From address invalid.
		add_filter( 'wp_mail_from', array( $this, 'get_valid_from_email' ) );

		$this->create_log_table();

		$this->test_email = new Test_Email( new Settings( new Provider_Registry(), new Credentials() ) );
	}

	public function get_valid_from_email() {
		return 'wordpress@example.org';
	}

	public function tear_down() {
		unset( $_REQUEST['_wpnonce'] );

		parent::tear_down();
	}

	private function request_as( string $role ) {
		$user_id = self::factory()->user->create(
			array(
				'role'       => $role,
				'user_email' => 'tester@example.org',
			)
		);
		wp_set_current_user( $user_id );

		$_REQUEST['_wpnonce'] = wp_create_nonce( Test_Email::ACTION );

		return $user_id;
	}

	private function run_handler(): string {
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( $location );
			}
		);

		try {
			$this->test_email->handle();
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		}

		return '';
	}

	public function test_add_hooks_registers_the_admin_post_action() {
		$this->test_email->add_hooks();

		$this->assertSame( 10, has_action( 'admin_post_smtp_send_test', array( $this->test_email, 'handle' ) ) );
	}

	public function test_pull_result_returns_the_result_once() {
		set_transient( 'smtp_send_test_7', array( 'success' => true ), 60 );

		$this->assertSame( array( 'success' => true ), Test_Email::pull_result( 7 ) );
		$this->assertNull( Test_Email::pull_result( 7 ) );
	}

	public function test_pull_result_is_null_when_there_is_nothing_to_show() {
		$this->assertNull( Test_Email::pull_result( 99 ) );
	}

	public function test_handle_rejects_a_missing_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->expectException( WPDieException::class );

		$this->test_email->handle();
	}

	public function test_handle_rejects_users_without_the_capability() {
		$this->request_as( 'subscriber' );

		$this->expectException( WPDieException::class );

		$this->test_email->handle();
	}

	public function test_handle_sends_the_email_and_redirects_to_the_test_tab() {
		$user_id = $this->request_as( 'administrator' );

		$location = $this->run_handler();

		$this->assertStringContainsString( 'tools.php?page=smtp&tab=test', $location );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertSame( 'tester@example.org', $mailer->get_recipient( 'to' )->address );

		$result = Test_Email::pull_result( $user_id );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'tester@example.org', $result['to'] );
		$this->assertSame( '', $result['error'] );
	}

	public function test_handle_reports_a_failure() {
		$user_id = $this->request_as( 'administrator' );

		add_filter(
			'pre_wp_mail',
			static function () {
				do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'Could not connect.' ) );

				return false;
			}
		);

		$this->run_handler();

		$result = Test_Email::pull_result( $user_id );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'Could not connect.', $result['error'] );
	}

	public function test_redact_removes_the_credentials_in_plain_text_and_base64() {
		update_option(
			Settings::OPTION,
			array(
				'host'     => 'smtp.example.com',
				'auth'     => true,
				'username' => 'user',
			)
		);
		( new Credentials() )->set( 'password', 'hunter2' );

		$log = implode(
			"\n",
			array(
				'AUTH LOGIN',
				'CLIENT -> SERVER: ' . base64_encode( 'hunter2' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'CLIENT -> SERVER: AUTH PLAIN ' . base64_encode( "\0user\0hunter2" ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'password is hunter2',
			)
		);

		$method = new ReflectionMethod( Test_Email::class, 'redact' );
		$method->setAccessible( true );
		$redacted = $method->invoke( $this->test_email, $log );

		$this->assertStringNotContainsString( 'hunter2', $redacted );
		$this->assertStringNotContainsString( base64_encode( 'hunter2' ), $redacted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertStringNotContainsString( base64_encode( "\0user\0hunter2" ), $redacted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$this->assertStringContainsString( '[redacted]', $redacted );
		$this->assertStringContainsString( 'AUTH LOGIN', $redacted );
	}

	public function test_redact_leaves_the_text_alone_without_a_password() {
		$method = new ReflectionMethod( Test_Email::class, 'redact' );
		$method->setAccessible( true );

		$this->assertSame( 'SERVER -> CLIENT: 220 ready', $method->invoke( $this->test_email, 'SERVER -> CLIENT: 220 ready' ) );
	}
}
