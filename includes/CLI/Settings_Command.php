<?php
/**
 * Class WordPress\SMTP\CLI\Settings_Command
 *
 * @package smtp
 */

namespace WordPress\SMTP\CLI;

use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Settings;

/**
 * Changes the SMTP settings.
 *
 * @since 1.2.0
 */
class Settings_Command {

	/**
	 * Value of a password field that asks for it to be read from STDIN.
	 *
	 * @since 1.2.0
	 */
	const READ_FROM_STDIN = '-';

	/**
	 * Settings.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Provider registry.
	 *
	 * @since 1.2.0
	 * @var Provider_Registry
	 */
	private $registry;

	/**
	 * Stream that secrets are read from.
	 *
	 * @since 1.2.0
	 * @var resource
	 */
	private $input;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings          $settings Settings.
	 * @param Provider_Registry $registry Provider registry.
	 * @param resource|null     $input    Stream that secrets are read from. Defaults to STDIN.
	 */
	public function __construct( Settings $settings, Provider_Registry $registry, $input = null ) {
		$this->settings = $settings;
		$this->registry = $registry;
		$this->input    = $input ?? STDIN;
	}

	/**
	 * Changes one or more settings and keeps the others.
	 *
	 * Nothing is saved when any of the values is not valid.
	 *
	 * Passwords can not be passed as arguments, since those end up in the shell history.
	 * Use `-` as the value to read the password from STDIN, or to get a hidden prompt in a terminal.
	 *
	 * ## OPTIONS
	 *
	 * <setting>...
	 * : One or more settings, as `key=value`. See `wp smtp status` for the keys.
	 *
	 * ## EXAMPLES
	 *
	 *     wp smtp settings set host=smtp.example.com port=587 encryption=tls username=me@example.com
	 *     printf '%s' "$SMTP_PASSWORD" | wp smtp settings set password=-
	 *     wp smtp settings set from_email=me@example.com from_name="My site" force_from=yes
	 *
	 * @since 1.2.0
	 *
	 * @param array $args Positional arguments.
	 */
	public function set( $args ) {
		$input = $this->parse( $args );
		$slug  = $this->settings->get_provider_slug();

		if ( isset( $input['provider'] ) ) {
			if ( null === $this->registry->get( $input['provider'] ) ) {
				/* translators: %s: provider slug */
				\WP_CLI::error( sprintf( __( 'Unknown provider "%s".', 'smtp' ), $input['provider'] ) );
			}
			$slug = $input['provider'];
		}

		$fields = $this->settings->get_fields( $slug );
		$values = array();
		$secret = '';

		foreach ( $input as $key => $value ) {
			if ( 'provider' === $key ) {
				$values[ $key ] = $value;
				continue;
			}

			if ( ! isset( $fields[ $key ] ) ) {
				/* translators: 1: setting key, 2: comma-separated list of setting keys */
				\WP_CLI::error( sprintf( __( 'Unknown setting "%1$s". Available settings: %2$s.', 'smtp' ), $key, implode( ', ', array_keys( $fields ) ) ) );
			}

			if ( $this->settings->is_locked( $fields[ $key ] ) ) {
				/* translators: 1: setting key, 2: constant name */
				\WP_CLI::error( sprintf( __( 'The setting "%1$s" is defined by the constant %2$s.', 'smtp' ), $key, $fields[ $key ]['constant'] ) );
			}

			$values[ $key ] = $this->validate( $key, $value, $fields[ $key ] );

			if ( 'password' === $fields[ $key ]['type'] ) {
				if ( '' !== $secret ) {
					\WP_CLI::error( __( 'Only one password can be read from STDIN at a time.', 'smtp' ) );
				}
				$secret = $key;
			}
		}

		if ( '' !== $secret ) {
			$values[ $secret ] = $this->read_secret( $fields[ $secret ]['label'] );
		}

		$this->settings->update( $values );

		if ( ! $this->settings->is_configured() ) {
			\WP_CLI::warning( __( 'The provider is still missing required settings, so emails are not sent through it yet.', 'smtp' ) );
		}

		\WP_CLI::success( __( 'Settings saved.', 'smtp' ) );
	}

	/**
	 * Splits `key=value` arguments.
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $args Positional arguments.
	 * @return array Values keyed by setting key.
	 */
	private function parse( array $args ): array {
		$input = array();

		foreach ( $args as $arg ) {
			$parts = explode( '=', $arg, 2 );

			if ( 2 !== count( $parts ) || '' === $parts[0] ) {
				/* translators: %s: command line argument */
				\WP_CLI::error( sprintf( __( 'Invalid argument "%s". Use key=value.', 'smtp' ), $parts[0] ) );
			}

			$input[ $parts[0] ] = $parts[1];
		}

		return $input;
	}

	/**
	 * Validates a value, since Settings::sanitize() would silently replace an invalid one.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key   Setting key.
	 * @param string $value Value from the command line.
	 * @param array  $field Normalized field.
	 * @return string|bool Value to save.
	 */
	private function validate( string $key, string $value, array $field ) {
		$valid = true;

		switch ( $field['type'] ) {
			case 'password':
				if ( self::READ_FROM_STDIN !== $value ) {
					/* translators: %s: setting key */
					\WP_CLI::error( sprintf( __( 'Passwords can not be passed as arguments. Use exactly %s=- to enter it at a hidden prompt, or pipe it through STDIN.', 'smtp' ), $key ) );
				}
				break;

			case 'checkbox':
				$value = '' === $value ? null : filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				$valid = null !== $value;
				break;

			case 'select':
				$valid = isset( $field['options'][ $value ] );
				break;

			case 'number':
				$valid = ctype_digit( $value ) && (int) $value > 0;
				break;

			case 'email':
				$valid = '' === $value || is_email( $value );
				break;
		}

		if ( ! $valid ) {
			/* translators: %s: setting key */
			\WP_CLI::error( sprintf( __( 'Invalid value for "%s".', 'smtp' ), $key ) );
		}

		return $value;
	}

	/**
	 * Reads a secret, with a hidden prompt in a terminal.
	 *
	 * @since 1.2.0
	 *
	 * @param string $label Field label.
	 * @return string Secret.
	 */
	private function read_secret( string $label ): string {
		if ( stream_isatty( $this->input ) ) {
			return \cli\prompt( $label, false, ': ', true );
		}

		$line   = fgets( $this->input );
		$secret = false === $line ? '' : rtrim( $line, "\r\n" );

		if ( '' === $secret ) {
			\WP_CLI::error( __( 'No password received on STDIN.', 'smtp' ) );
		}

		return $secret;
	}
}
