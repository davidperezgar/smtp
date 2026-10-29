<?php
/**
 * Class WordPress\SMTP\Admin\Test_Email
 *
 * @package smtp
 */

namespace WordPress\SMTP\Admin;

use WordPress\SMTP\Settings;

/**
 * Sends a test email to the current user and reports the outcome.
 *
 * @since 0.1.0
 */
class Test_Email {

	/**
	 * Action and nonce name.
	 *
	 * @since 0.1.0
	 */
	const ACTION = 'smtp_send_test';

	/**
	 * Settings.
	 *
	 * @since 0.1.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers the hooks.
	 *
	 * @since 0.1.0
	 */
	public function add_hooks() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Returns the last test result of a user and forgets it.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user_id User ID.
	 * @return array|null Result with `success`, `to`, `error` and `debug`, or null when there is none.
	 */
	public static function pull_result( int $user_id ): ?array {
		$key    = self::ACTION . '_' . $user_id;
		$result = get_transient( $key );

		if ( ! is_array( $result ) ) {
			return null;
		}

		delete_transient( $key );

		return $result;
	}

	/**
	 * Handles the test email request.
	 *
	 * @since 0.1.0
	 */
	public function handle() {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'smtp' ), '', array( 'response' => 403 ) );
		}

		$user  = wp_get_current_user();
		$debug = array();
		$error = '';

		// PHPMailer property names are not snake_case.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$capture_debug = static function ( $phpmailer ) use ( &$debug ) {
			$phpmailer->SMTPDebug   = 2;
			$phpmailer->Debugoutput = static function ( $message ) use ( &$debug ) {
				$debug[] = trim( (string) $message );
			};
		};
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$capture_error = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error->get_error_message();
		};

		add_action( 'phpmailer_init', $capture_debug, PHP_INT_MAX );
		add_action( 'wp_mail_failed', $capture_error );

		$sent = wp_mail(
			$user->user_email,
			/* translators: %s: site name */
			sprintf( __( 'SMTP test email from %s', 'smtp' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			__( 'This is a test email sent by the SMTP plugin. If you are reading it, your settings work.', 'smtp' )
		);

		remove_action( 'phpmailer_init', $capture_debug, PHP_INT_MAX );
		remove_action( 'wp_mail_failed', $capture_error );

		set_transient(
			self::ACTION . '_' . $user->ID,
			array(
				'success' => $sent,
				'to'      => $user->user_email,
				'error'   => $error,
				'debug'   => $this->redact( implode( "\n", $debug ) ),
			),
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'options-general.php?page=' . Admin_Page::PAGE_SLUG . '&tab=test' ) );
		exit;
	}

	/**
	 * Removes the credentials from the connection log, in plain text and base64.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Connection log.
	 * @return string Connection log without credentials.
	 */
	private function redact( string $text ): string {
		$values   = $this->settings->get_provider_values();
		$username = isset( $values['username'] ) ? (string) $values['username'] : '';
		$password = isset( $values['password'] ) ? (string) $values['password'] : '';
		$secrets  = array();

		if ( '' !== $password ) {
			$secrets[] = $password;
			$secrets[] = base64_encode( $password ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Needed to match the SMTP AUTH exchange.
			$secrets[] = base64_encode( "\0" . $username . "\0" . $password ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Needed to match the SMTP AUTH exchange.
		}

		return str_replace( $secrets, '[redacted]', $text );
	}
}
