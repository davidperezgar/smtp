<?php
/**
 * Class WordPress\SMTP\Credentials
 *
 * @package smtp
 */

namespace WordPress\SMTP;

/**
 * Reads and writes secret values (passwords, API keys).
 *
 * All access to secrets goes through this class so the storage can be replaced
 * (for example by the core credentials API) without touching the rest of the plugin.
 *
 * @since 1.2.0
 */
class Credentials {

	/**
	 * Name of the option that stores the secrets.
	 *
	 * @since 1.2.0
	 */
	const OPTION = 'smtp_credentials';

	/**
	 * Returns a stored secret.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key Secret key.
	 * @return string Secret value, or an empty string when not set.
	 */
	public function get( string $key ): string {
		$secrets = get_option( self::OPTION, array() );

		if ( ! is_array( $secrets ) || ! isset( $secrets[ $key ] ) || ! is_string( $secrets[ $key ] ) ) {
			return '';
		}

		return $secrets[ $key ];
	}

	/**
	 * Stores a secret.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key   Secret key.
	 * @param string $value Secret value.
	 */
	public function set( string $key, string $value ) {
		$secrets = get_option( self::OPTION, array() );
		if ( ! is_array( $secrets ) ) {
			$secrets = array();
		}

		$secrets[ $key ] = $value;

		// Not autoloaded: secrets are only needed when sending mail or saving settings.
		update_option( self::OPTION, $secrets, false );
	}
}
