<?php
/**
 * Class WordPress\SMTP\CLI\Command
 *
 * @package smtp
 */

namespace WordPress\SMTP\CLI;

use WordPress\SMTP\Admin\Test_Email;
use WordPress\SMTP\Settings;

/**
 * Shows the SMTP configuration and sends test emails.
 *
 * @since 1.2.0
 */
class Command {

	/**
	 * Settings.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Test email sender.
	 *
	 * @since 1.2.0
	 * @var Test_Email
	 */
	private $test_email;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings   $settings   Settings.
	 * @param Test_Email $test_email Test email sender.
	 */
	public function __construct( Settings $settings, Test_Email $test_email ) {
		$this->settings   = $settings;
		$this->test_email = $test_email;
	}

	/**
	 * Shows the active provider, its settings and where each value comes from.
	 *
	 * Passwords are never shown, only whether they are set.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp smtp status
	 *     wp smtp status --format=json
	 *
	 * @since 1.2.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		$rows = array(
			array(
				'field'  => 'provider',
				'value'  => $this->settings->get_provider_slug(),
				'source' => '',
			),
			array(
				'field'  => 'configured',
				'value'  => $this->settings->is_configured() ? 'yes' : 'no',
				'source' => '',
			),
		);

		foreach ( $this->settings->get_fields() as $key => $field ) {
			$rows[] = array(
				'field'  => $key,
				'value'  => $this->format_value( $this->settings->get( $key ), $field ),
				'source' => $this->settings->get_source( $key ),
			);
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'field', 'value', 'source' ) );
	}

	/**
	 * Sends a test email.
	 *
	 * Exits with a non-zero code when the email can not be sent.
	 *
	 * ## OPTIONS
	 *
	 * [<to>]
	 * : Recipient address. Defaults to the admin email of the site.
	 *
	 * [--connection-log]
	 * : Print the connection log, without the password.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smtp test
	 *     wp smtp test someone@example.com --connection-log
	 *
	 * @since 1.2.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function test( $args, $assoc_args ) {
		if ( ! $this->settings->is_configured() ) {
			\WP_CLI::warning( __( 'SMTP is not configured, so this does not test an SMTP connection.', 'smtp' ) );
		}

		$result = $this->test_email->send( $args[0] ?? (string) get_option( 'admin_email' ) );

		if ( ! empty( $assoc_args['connection-log'] ) && '' !== $result['debug'] ) {
			\WP_CLI::log( $result['debug'] );
		}

		if ( ! $result['success'] ) {
			\WP_CLI::error( '' !== $result['error'] ? $result['error'] : __( 'The email could not be sent.', 'smtp' ) );
		}

		/* translators: %s: email address */
		\WP_CLI::success( sprintf( __( 'Test email sent to %s.', 'smtp' ), $result['to'] ) );
	}

	/**
	 * Formats a field value for the status output.
	 *
	 * @since 1.2.0
	 *
	 * @param string|int|bool $value Resolved value.
	 * @param array           $field Normalized field.
	 * @return string Value to show.
	 */
	private function format_value( $value, array $field ): string {
		if ( 'password' === $field['type'] ) {
			return '' !== $value ? 'set' : 'not set';
		}

		if ( 'checkbox' === $field['type'] ) {
			return $value ? 'yes' : 'no';
		}

		return (string) $value;
	}
}
