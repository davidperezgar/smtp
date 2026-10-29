<?php
/**
 * Class WordPress\SMTP\Mailer
 *
 * @package smtp
 */

namespace WordPress\SMTP;

/**
 * Applies the configured provider and From settings to outgoing emails.
 *
 * Nothing is changed until the active provider is fully configured.
 *
 * @since 0.1.0
 */
class Mailer {

	/**
	 * Settings.
	 *
	 * @since 0.1.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Provider registry.
	 *
	 * @since 0.1.0
	 * @var Provider_Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param Settings          $settings Settings.
	 * @param Provider_Registry $registry Provider registry.
	 */
	public function __construct( Settings $settings, Provider_Registry $registry ) {
		$this->settings = $settings;
		$this->registry = $registry;
	}

	/**
	 * Registers the hooks.
	 *
	 * @since 0.1.0
	 */
	public function add_hooks() {
		add_action( 'phpmailer_init', array( $this, 'configure' ) );
		add_filter( 'wp_mail_from', array( $this, 'filter_from_email' ), PHP_INT_MAX );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), PHP_INT_MAX );
	}

	/**
	 * Lets the active provider configure PHPMailer.
	 *
	 * @since 0.1.0
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 */
	public function configure( $phpmailer ) {
		if ( ! $this->settings->is_configured() ) {
			return;
		}

		$provider = $this->registry->get( $this->settings->get_provider_slug() );
		if ( ! $provider ) {
			return;
		}

		call_user_func( $provider['configure'], $phpmailer, $this->settings->get_provider_values() );
	}

	/**
	 * Filters the From email address.
	 *
	 * @since 0.1.0
	 *
	 * @param string $email From email address.
	 * @return string From email address.
	 */
	public function filter_from_email( $email ) {
		if ( ! $this->settings->is_configured() ) {
			return $email;
		}

		$from_email = (string) $this->settings->get( 'from_email' );
		if ( '' === $from_email || ! is_email( $from_email ) ) {
			return $email;
		}

		if ( $this->settings->get( 'force_from' ) || $this->get_default_from_email() === $email ) {
			return $from_email;
		}

		return $email;
	}

	/**
	 * Filters the From name.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name From name.
	 * @return string From name.
	 */
	public function filter_from_name( $name ) {
		if ( ! $this->settings->is_configured() ) {
			return $name;
		}

		$from_name = (string) $this->settings->get( 'from_name' );
		if ( '' === $from_name ) {
			return $name;
		}

		if ( $this->settings->get( 'force_from' ) || 'WordPress' === $name ) {
			return $from_name;
		}

		return $name;
	}

	/**
	 * Returns the From email address WordPress uses when none is set.
	 *
	 * @since 0.1.0
	 *
	 * @return string Default From email address.
	 */
	private function get_default_from_email(): string {
		$sitename = wp_parse_url( network_home_url(), PHP_URL_HOST );

		if ( is_string( $sitename ) && 0 === strpos( $sitename, 'www.' ) ) {
			$sitename = substr( $sitename, 4 );
		}

		return 'wordpress@' . $sitename;
	}
}
