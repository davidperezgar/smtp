<?php
/**
 * Class WordPress\SMTP\Delivery_Tracker
 *
 * @package smtp
 */

namespace WordPress\SMTP;

/**
 * Finds out whether the email being sent goes through this plugin or through someone else.
 *
 * Other mailer plugins can replace `wp_mail()`, short-circuit it with `pre_wp_mail`, or change
 * PHPMailer after this plugin configured it. In all those cases the email log must not credit
 * the active provider, and the admin screens must say that the settings are not in use.
 *
 * @since 1.2.0
 */
class Delivery_Tracker {

	/**
	 * Log provider value for emails sent by the default WordPress mailer.
	 *
	 * @since 1.2.0
	 */
	const PROVIDER_DEFAULT = 'default';

	/**
	 * Log provider value for emails that were delivered by another plugin.
	 *
	 * @since 1.2.0
	 */
	const PROVIDER_OTHER = 'other';

	/**
	 * PHPMailer properties that must keep the values set by the provider.
	 *
	 * @since 1.2.0
	 */
	const TRACKED_PROPERTIES = array( 'Mailer', 'Host', 'Port', 'SMTPSecure', 'SMTPAuth', 'Username' );

	/**
	 * Mailers PHPMailer uses when nobody configures it.
	 *
	 * @since 1.2.0
	 */
	const DEFAULT_MAILERS = array( 'mail', 'sendmail', 'qmail' );

	/**
	 * Settings.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * PHPMailer state right after the provider configured it, or null when it did not.
	 *
	 * @since 1.2.0
	 * @var array|null
	 */
	private $snapshot = null;

	/**
	 * Delivery of the current email, once `phpmailer_init` finished: `handled`, `default` or `other`.
	 *
	 * @since 1.2.0
	 * @var string|null
	 */
	private $state = null;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers the hooks.
	 *
	 * @since 1.2.0
	 */
	public function add_hooks() {
		add_action( 'phpmailer_init', array( $this, 'inspect' ), PHP_INT_MAX );
		add_action( 'wp_mail_succeeded', array( $this, 'reset' ), PHP_INT_MAX );
		add_action( 'wp_mail_failed', array( $this, 'reset' ), PHP_INT_MAX );
	}

	/**
	 * Remembers how the provider configured PHPMailer.
	 *
	 * @since 1.2.0
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 */
	public function remember( $phpmailer ) {
		$this->snapshot = $this->take_snapshot( $phpmailer );
	}

	/**
	 * Checks, once every `phpmailer_init` callback ran, who is going to deliver the email.
	 *
	 * @since 1.2.0
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 */
	public function inspect( $phpmailer ) {
		if ( null !== $this->snapshot ) {
			$this->state = $this->snapshot === $this->take_snapshot( $phpmailer ) ? 'handled' : self::PROVIDER_OTHER;
			return;
		}

		$mailer      = is_object( $phpmailer ) && isset( $phpmailer->Mailer ) ? (string) $phpmailer->Mailer : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->state = in_array( $mailer, self::DEFAULT_MAILERS, true ) ? self::PROVIDER_DEFAULT : self::PROVIDER_OTHER;
	}

	/**
	 * Forgets the current email, once it was logged.
	 *
	 * @since 1.2.0
	 */
	public function reset() {
		$this->snapshot = null;
		$this->state    = null;
	}

	/**
	 * Returns the provider to log for the current email.
	 *
	 * @since 1.2.0
	 *
	 * @return string Slug of the active provider, `default` for the WordPress mailer or `other` for another plugin.
	 */
	public function get_provider(): string {
		$configured = $this->settings->is_configured();

		if ( 'handled' === $this->state ) {
			// A replaced wp_mail(), like the one in FluentSMTP, can change PHPMailer after phpmailer_init.
			return '' === $this->get_wp_mail_owner() ? $this->settings->get_provider_slug() : self::PROVIDER_OTHER;
		}

		if ( null !== $this->state ) {
			// Another plugin may also send through its own copy of wp_mail() and the default mailer.
			return self::PROVIDER_DEFAULT === $this->state && '' === $this->get_wp_mail_owner() ? self::PROVIDER_DEFAULT : self::PROVIDER_OTHER;
		}

		// PHPMailer was never set up: either the email failed before that, or someone else sent it.
		if ( '' !== $this->get_wp_mail_owner() || has_filter( 'pre_wp_mail' ) ) {
			return self::PROVIDER_OTHER;
		}

		return $configured ? $this->settings->get_provider_slug() : self::PROVIDER_DEFAULT;
	}

	/**
	 * Returns the name of the plugin that replaced `wp_mail()`, if any.
	 *
	 * @since 1.2.0
	 *
	 * @return string Plugin name, a generic label when it can not be identified, or an empty string.
	 */
	public function get_wp_mail_owner(): string {
		return $this->get_function_owner( 'wp_mail' );
	}

	/**
	 * Returns the name of the plugin that defines a pluggable function instead of WordPress.
	 *
	 * @since 1.2.0
	 *
	 * @param string $function_name Function name.
	 * @return string Plugin name, a generic label when it can not be identified, or an empty string
	 *                when WordPress defines the function or it does not exist.
	 */
	public function get_function_owner( string $function_name ): string {
		if ( ! function_exists( $function_name ) ) {
			return '';
		}

		$file = ( new \ReflectionFunction( $function_name ) )->getFileName();

		if ( ! is_string( $file ) || wp_normalize_path( ABSPATH . WPINC . '/pluggable.php' ) === wp_normalize_path( $file ) ) {
			return '';
		}

		return $this->get_plugin_name( wp_normalize_path( $file ) );
	}

	/**
	 * Returns the name of the plugin a file belongs to.
	 *
	 * @since 1.2.0
	 *
	 * @param string $file Normalized file path.
	 * @return string Plugin name, or a generic label.
	 */
	private function get_plugin_name( string $file ): string {
		$fallback    = __( 'Another plugin', 'smtp' );
		$plugins_dir = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );

		if ( 0 !== strpos( $file, $plugins_dir ) ) {
			return $fallback;
		}

		$folder = strtok( substr( $file, strlen( $plugins_dir ) ), '/' );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( get_plugins() as $basename => $plugin ) {
			if ( 0 === strpos( $basename, $folder . '/' ) && ! empty( $plugin['Name'] ) ) {
				return $plugin['Name'];
			}
		}

		return $fallback;
	}

	/**
	 * Returns the PHPMailer properties a provider sets.
	 *
	 * @since 1.2.0
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 * @return array Class name and property values.
	 */
	private function take_snapshot( $phpmailer ): array {
		$snapshot = array( 'class' => is_object( $phpmailer ) ? get_class( $phpmailer ) : '' );

		foreach ( self::TRACKED_PROPERTIES as $property ) {
			$snapshot[ $property ] = is_object( $phpmailer ) && isset( $phpmailer->$property ) ? $phpmailer->$property : null;
		}

		return $snapshot;
	}
}
