<?php
/**
 * Class WordPress\SMTP\Error_Hints
 *
 * @package smtp
 */

namespace WordPress\SMTP;

/**
 * Explains what to check when an email could not be sent.
 *
 * Reads the connection log instead of the error message: WordPress translates the PHPMailer error
 * messages, while the server replies and the SMTP class log lines stay the same on every site.
 *
 * @since 1.2.0
 */
class Error_Hints {

	/**
	 * Returns a hint for a failed connection log.
	 *
	 * @since 1.2.0
	 *
	 * @param string $log Connection log.
	 * @return string Translated hint, or an empty string when the problem is not recognized.
	 */
	public static function get( string $log ): string {
		if ( preg_match( '/command failed: 530\b/', $log ) ) {
			return __( 'The server requires a login. Turn on Authentication and enter your username and password.', 'smtp' );
		}

		if ( preg_match( '/command failed: 53[458]\b/', $log ) ) {
			return __( 'The server rejected the login. Check the username and password. Many providers need a separate SMTP login and SMTP key or app password, and an API key or your account password will not work.', 'smtp' );
		}

		if ( preg_match( '/Failed to connect to server: [^\n]*(getaddrinfo|getaddresses|not known|nodename)/i', $log ) ) {
			return __( 'The host name could not be found. Check the Host setting for typos.', 'smtp' );
		}

		if ( false !== strpos( $log, 'Failed to connect to server' ) ) {
			return __( 'The server could not be reached. Check the host and port. Port 465 needs SSL and port 587 needs TLS. If they are correct, your hosting company may be blocking the port, so try 587 or ask them.', 'smtp' );
		}

		if ( false !== strpos( $log, 'STARTTLS command failed' ) || preg_match( '/CLIENT -> SERVER: STARTTLS\s+SERVER -> CLIENT: 220(?![\s\S]*CLIENT -> SERVER: EHLO)/', $log ) ) {
			return __( 'The encrypted connection could not be set up. Check that the Encryption setting matches the port, and that the host name matches the certificate of the server.', 'smtp' );
		}

		if ( false !== strpos( $log, 'MAIL FROM command failed' ) ) {
			return __( 'The server rejected the sender. Set the From email to an address your provider has verified, and turn on Force from if another plugin changes it.', 'smtp' );
		}

		if ( false !== strpos( $log, 'RCPT TO command failed' ) ) {
			return __( 'The server rejected the recipient. Check the address. Your provider may also only allow certain recipients, for example on a trial account.', 'smtp' );
		}

		if ( preg_match( '/DATA(?: END)? command failed/', $log ) ) {
			return __( 'The server rejected the message. Check the logs and sending limits of your provider.', 'smtp' );
		}

		if ( false !== strpos( $log, 'Sending with mail()' ) && false !== strpos( $log, 'Result: false' ) ) {
			return __( 'This server can not send email with the default WordPress mailer. Configure an SMTP server in the Settings tab.', 'smtp' );
		}

		return '';
	}
}
