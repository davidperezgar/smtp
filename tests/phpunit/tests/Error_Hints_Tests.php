<?php
/**
 * Tests for the Error_Hints class.
 *
 * @package smtp
 */

use WordPress\SMTP\Error_Hints;

class Error_Hints_Tests extends WP_UnitTestCase {

	/**
	 * @dataProvider data_logs
	 */
	public function test_get_recognizes_the_problem( string $log, string $expected ) {
		$this->assertStringContainsString( $expected, Error_Hints::get( $log ) );
	}

	public function data_logs(): array {
		return array(
			'login required'    => array( "CLIENT -> SERVER: MAIL FROM:<a@example.com>\nSERVER -> CLIENT: 530 5.7.0 Authentication required\nSMTP ERROR: MAIL FROM command failed: 530 5.7.0 Authentication required", 'requires a login' ),
			'wrong login'       => array( "CLIENT -> SERVER: AUTH CRAM-MD5\nSERVER -> CLIENT: 334 PDEuNj4=\nCLIENT -> SERVER: [credentials hidden]\nSERVER -> CLIENT: 535 5.7.8 Authentication failed\nSMTP ERROR: Username command failed: 535 5.7.8 Authentication failed\nSMTP Error: Could not authenticate.", 'rejected the login' ),
			'app password'      => array( 'SMTP ERROR: Password command failed: 534-5.7.9 Application-specific password required', 'rejected the login' ),
			'unknown host'      => array( 'SMTP ERROR: Failed to connect to server: php_network_getaddresses: getaddrinfo failed: Name or service not known (0)', 'could not be found' ),
			'refused'           => array( 'SMTP ERROR: Failed to connect to server: Connection refused (111)', 'could not be reached' ),
			'timeout'           => array( 'SMTP ERROR: Failed to connect to server: Connection timed out (110)', 'could not be reached' ),
			'no starttls'       => array( "CLIENT -> SERVER: STARTTLS\nSERVER -> CLIENT: 502 5.5.1 Not implemented\nSMTP ERROR: STARTTLS command failed: 502 5.5.1 Not implemented", 'encrypted connection' ),
			'handshake failed'  => array( "CLIENT -> SERVER: STARTTLS\nSERVER -> CLIENT: 220 2.0.0 Ready to start TLS\nSMTP Error: Could not connect to SMTP host.", 'encrypted connection' ),
			'sender rejected'   => array( 'SMTP ERROR: MAIL FROM command failed: 550 5.7.1 Sender not verified', 'rejected the sender' ),
			'recipient refused' => array( 'SMTP ERROR: RCPT TO command failed: 550 5.1.1 User unknown', 'rejected the recipient' ),
			'message refused'   => array( 'SMTP ERROR: DATA END command failed: 554 5.7.1 Message rejected', 'rejected the message' ),
			'mail() failed'     => array( "Sending with mail()\nSendmail path: /usr/sbin/sendmail\nResult: false", 'default WordPress mailer' ),
		);
	}

	public function test_get_prefers_the_failing_step_over_earlier_successful_ones() {
		$log = "CLIENT -> SERVER: STARTTLS\nSERVER -> CLIENT: 220 2.0.0 Ready to start TLS\nCLIENT -> SERVER: EHLO mba.local\nSERVER -> CLIENT: 250 SIZE 20971520\nSMTP ERROR: MAIL FROM command failed: 550 5.7.1 Sender not verified";

		$this->assertStringContainsString( 'rejected the sender', Error_Hints::get( $log ) );
	}

	/**
	 * @dataProvider data_unrecognized_logs
	 */
	public function test_get_returns_nothing_when_the_problem_is_not_recognized( string $log ) {
		$this->assertSame( '', Error_Hints::get( $log ) );
	}

	public function data_unrecognized_logs(): array {
		return array(
			'empty log'  => array( '' ),
			'unknown'    => array( 'SMTP ERROR: QUIT command failed: 421 Bye' ),
			'mail() ok'  => array( "Sending with mail()\nResult: true" ),
			'successful' => array( "CLIENT -> SERVER: STARTTLS\nSERVER -> CLIENT: 220 2.0.0 Ready to start TLS\nCLIENT -> SERVER: EHLO mba.local\nSERVER -> CLIENT: 235 2.0.0 Authentication succeeded" ),
		);
	}
}
