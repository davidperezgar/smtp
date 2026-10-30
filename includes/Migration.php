<?php
/**
 * Class WordPress\SMTP\Migration
 *
 * @package smtp
 */

namespace WordPress\SMTP;

use WordPress\SMTP\Admin\Admin_Page;

/**
 * Imports the settings of the legacy SMTP plugin (version 1.x) on the first load.
 *
 * This plugin replaces the one published as `smtp` on WordPress.org, so sites upgrade in place and
 * the activation hook never runs again. The import therefore runs on `init`, once, and keeps the
 * legacy option untouched until the plugin is uninstalled.
 *
 * @since 1.2.0
 */
class Migration {

	/**
	 * Name of the option where the legacy plugin stored its settings.
	 *
	 * @since 1.2.0
	 */
	const LEGACY_OPTION = 'smtp_options';

	/**
	 * Name of the option that records that the import was already attempted.
	 *
	 * @since 1.2.0
	 */
	const DONE_OPTION = 'smtp_legacy_migrated';

	/**
	 * Name of the option that holds the pending admin notice.
	 *
	 * @since 1.2.0
	 */
	const NOTICE_OPTION = 'smtp_migration_notice';

	/**
	 * Notice shown after the settings were imported.
	 *
	 * @since 1.2.0
	 */
	const NOTICE_MIGRATED = 'migrated';

	/**
	 * Notice shown when the settings were imported but the password could not be.
	 *
	 * @since 1.2.0
	 */
	const NOTICE_PASSWORD_MISSING = 'password_missing';

	/**
	 * Key the legacy plugin used to encrypt the password. It was hardcoded in its public source code.
	 *
	 * @since 1.2.0
	 */
	const LEGACY_CRYPT_KEY = '-J5:2Yqd?Ri9wLjN';

	/**
	 * Credentials storage.
	 *
	 * @since 1.2.0
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Callable that decrypts a legacy password, or null to use mcrypt when available.
	 *
	 * @since 1.2.0
	 * @var callable|null
	 */
	private $decrypter;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Credentials   $credentials Credentials storage.
	 * @param callable|null $decrypter   Optional callable that receives the stored legacy password and
	 *                                   returns it decrypted, or an empty string when it can not.
	 */
	public function __construct( Credentials $credentials, ?callable $decrypter = null ) {
		$this->credentials = $credentials;
		$this->decrypter   = $decrypter;
	}

	/**
	 * Registers the hooks.
	 *
	 * @since 1.2.0
	 */
	public function add_hooks() {
		// Priority 0: users are available, and it runs before most mail is sent.
		add_action( 'init', array( $this, 'maybe_migrate' ), 0 );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'render_notice' ) );
		}
	}

	/**
	 * Imports the legacy settings, once.
	 *
	 * Nothing is imported when the new settings already exist, or when the legacy plugin was never
	 * configured. The legacy option is never modified.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True when settings were imported.
	 */
	public function maybe_migrate(): bool {
		if ( get_option( self::DONE_OPTION ) ) {
			return false;
		}

		update_option( self::DONE_OPTION, '1' );

		$legacy = get_option( self::LEGACY_OPTION );

		if ( ! is_array( $legacy ) || false !== get_option( Settings::OPTION, false ) ) {
			return false;
		}

		$settings = $this->map_settings( $legacy );
		if ( null === $settings ) {
			return false;
		}

		update_option( Settings::OPTION, $settings );

		$password = $this->get_password( $legacy );
		if ( '' !== $password ) {
			$this->credentials->set( 'password', $password );
			update_option( self::NOTICE_OPTION, self::NOTICE_MIGRATED );
		} elseif ( $settings['auth'] ) {
			update_option( self::NOTICE_OPTION, self::NOTICE_PASSWORD_MISSING );
		} else {
			update_option( self::NOTICE_OPTION, self::NOTICE_MIGRATED );
		}

		return true;
	}

	/**
	 * Shows the pending notice, if any.
	 *
	 * @since 1.2.0
	 */
	public function render_notice() {
		$notice = get_option( self::NOTICE_OPTION );

		if ( ! $notice || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$url = Admin_Page::get_url( 'settings' );

		// Read-only parameter, no state is changed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$on_settings_page = isset( $_GET['page'], $_GET['tab'] ) && 'smtp' === sanitize_key( wp_unslash( $_GET['page'] ) ) && 'settings' === sanitize_key( wp_unslash( $_GET['tab'] ) );

		if ( self::NOTICE_PASSWORD_MISSING === $notice ) {
			if ( '' !== $this->credentials->get( 'password' ) || defined( 'SMTP_PASSWORD' ) ) {
				delete_option( self::NOTICE_OPTION );
				return;
			}

			?>
			<div class="notice notice-warning">
				<p>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: URL of the SMTP settings page */
							__( 'Your SMTP settings were imported from the previous version of the plugin, but the password could not be. <a href="%s">Enter your SMTP password</a> to keep sending email through your server.', 'smtp' ),
							esc_url( $url )
						),
						array( 'a' => array( 'href' => array() ) )
					);
					?>
				</p>
			</div>
			<?php
			return;
		}

		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: URL of the SMTP settings page */
						__( 'Your SMTP settings were imported from the previous version of the plugin. <a href="%s">Review the settings</a>.', 'smtp' ),
						esc_url( $url )
					),
					array( 'a' => array( 'href' => array() ) )
				);
				?>
			</p>
		</div>
		<?php

		if ( $on_settings_page ) {
			delete_option( self::NOTICE_OPTION );
		}
	}

	/**
	 * Maps the legacy options to the settings of this plugin.
	 *
	 * @since 1.2.0
	 *
	 * @param array $legacy Legacy options.
	 * @return array|null Settings, or null when the legacy plugin was never configured.
	 */
	private function map_settings( array $legacy ): ?array {
		$host     = isset( $legacy['host'] ) && is_string( $legacy['host'] ) ? $legacy['host'] : '';
		$username = isset( $legacy['username'] ) && is_string( $legacy['username'] ) ? $legacy['username'] : '';

		// The legacy plugin stored this placeholder on activation, so it was never configured.
		if ( '' === trim( $host ) || ( 'http://localhost' === trim( $host ) && '' === $username ) ) {
			return null;
		}

		$host = preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', trim( $host ) );
		$host = sanitize_text_field( (string) $host );

		if ( '' === $host ) {
			return null;
		}

		$port       = isset( $legacy['port'] ) ? absint( $legacy['port'] ) : 0;
		$encryption = isset( $legacy['smtp_secure'] ) && is_string( $legacy['smtp_secure'] ) ? $legacy['smtp_secure'] : '';

		$settings = array(
			'provider'   => 'smtp',
			'host'       => $host,
			'port'       => $port > 1 ? $port : 25,
			'encryption' => in_array( $encryption, array( 'ssl', 'tls' ), true ) ? $encryption : 'none',
			// The legacy plugin authenticated whenever a username was set.
			'auth'       => '' !== $username,
			'username'   => sanitize_text_field( $username ),
			'from_email' => '',
			'from_name'  => '',
			'force_from' => false,
		);

		// The legacy plugin always sent email as the first user of the site.
		$admin = get_userdata( 1 );
		if ( $admin && is_email( $admin->user_email ) ) {
			$settings['from_email'] = $admin->user_email;
			$settings['from_name']  = sanitize_text_field( $admin->display_name );
			$settings['force_from'] = true;
		}

		return $settings;
	}

	/**
	 * Returns the decrypted legacy password.
	 *
	 * @since 1.2.0
	 *
	 * @param array $legacy Legacy options.
	 * @return string Password, or an empty string when there is none or it can not be decrypted.
	 */
	private function get_password( array $legacy ): string {
		$encrypted = isset( $legacy['password'] ) && is_string( $legacy['password'] ) ? $legacy['password'] : '';

		if ( '' === $encrypted ) {
			return '';
		}

		$decrypter = $this->decrypter ? $this->decrypter : array( $this, 'decrypt_with_mcrypt' );
		$password  = call_user_func( $decrypter, $encrypted );

		return is_string( $password ) ? $password : '';
	}

	/**
	 * Decrypts a legacy password the way the legacy plugin encrypted it: Rijndael-256 in CBC mode.
	 *
	 * The mcrypt extension was removed in PHP 7.2 and no other extension supports Rijndael-256, so
	 * this only works on servers that still provide it.
	 *
	 * @since 1.2.0
	 *
	 * @param string $encrypted Encrypted password, as stored by the legacy plugin.
	 * @return string Password, or an empty string when mcrypt is not available.
	 */
	public function decrypt_with_mcrypt( string $encrypted ): string {
		if ( ! function_exists( 'mcrypt_decrypt' ) ) {
			return '';
		}

		$key = md5( self::LEGACY_CRYPT_KEY );
		$iv  = md5( $key );

		// phpcs:disable PHPCompatibility.FunctionUse.RemovedFunctions.mcrypt_decryptDeprecatedRemoved, PHPCompatibility.Extensions.RemovedExtensions.mcryptDeprecatedRemoved, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decrypted = mcrypt_decrypt( 'rijndael-256', $key, base64_decode( $encrypted ), 'cbc', $iv );
		// phpcs:enable PHPCompatibility.FunctionUse.RemovedFunctions.mcrypt_decryptDeprecatedRemoved, PHPCompatibility.Extensions.RemovedExtensions.mcryptDeprecatedRemoved, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		return is_string( $decrypted ) ? rtrim( $decrypted, "\0" ) : '';
	}
}
