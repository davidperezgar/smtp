<?php
/**
 * Class WordPress\SMTP\Provider_Registry
 *
 * @package smtp
 */

namespace WordPress\SMTP;

/**
 * Holds the available mail providers.
 *
 * A provider is an array with the following keys:
 *
 * - `label` (string): Human readable name.
 * - `fields` (array): Settings fields, keyed by field key. Each field accepts `label`, `type`
 *   (`text`, `number`, `email`, `password`, `select`, `checkbox`), `default`, `options` (for
 *   `select`), `description` and `constant` (name of a constant that overrides the value).
 * - `required` (string[]): Field keys that must have a value for the provider to be usable.
 * - `configure` (callable): Receives the `PHPMailer` instance and the resolved field values.
 *
 * @since 1.2.0
 */
class Provider_Registry {

	/**
	 * Returns all valid providers.
	 *
	 * @since 1.2.0
	 *
	 * @return array[] Providers keyed by slug.
	 */
	public function get_all(): array {
		/**
		 * Filters the available mail providers.
		 *
		 * @since 1.2.0
		 *
		 * @param array[] $providers Providers keyed by slug.
		 */
		$providers = apply_filters( 'smtp_providers', array( 'smtp' => $this->get_generic_provider() ) );

		if ( ! is_array( $providers ) ) {
			return array();
		}

		$valid = array();
		foreach ( $providers as $slug => $provider ) {
			if ( ! is_string( $slug ) || '' === $slug || ! is_array( $provider ) ) {
				continue;
			}
			if ( empty( $provider['label'] ) || ! is_string( $provider['label'] ) ) {
				continue;
			}
			if ( empty( $provider['configure'] ) || ! is_callable( $provider['configure'] ) ) {
				continue;
			}

			$provider['fields']   = isset( $provider['fields'] ) && is_array( $provider['fields'] ) ? $provider['fields'] : array();
			$provider['required'] = isset( $provider['required'] ) && is_array( $provider['required'] ) ? $provider['required'] : array();

			$valid[ $slug ] = $provider;
		}

		return $valid;
	}

	/**
	 * Returns a single provider.
	 *
	 * @since 1.2.0
	 *
	 * @param string $slug Provider slug.
	 * @return array|null Provider definition, or null when unknown.
	 */
	public function get( string $slug ): ?array {
		$providers = $this->get_all();

		return $providers[ $slug ] ?? null;
	}

	/**
	 * Returns the slug of the provider used when none is saved.
	 *
	 * @since 1.2.0
	 *
	 * @return string Provider slug, or an empty string when there are no providers.
	 */
	public function get_default_slug(): string {
		$providers = $this->get_all();

		if ( isset( $providers['smtp'] ) ) {
			return 'smtp';
		}

		$slugs = array_keys( $providers );

		return (string) reset( $slugs );
	}

	/**
	 * Returns the built-in Generic SMTP provider.
	 *
	 * @since 1.2.0
	 *
	 * @return array Provider definition.
	 */
	private function get_generic_provider(): array {
		return array(
			'label'     => __( 'Generic SMTP', 'smtp' ),
			'required'  => array( 'host' ),
			'fields'    => array(
				'host'       => array(
					'label'    => __( 'Host', 'smtp' ),
					'type'     => 'text',
					'constant' => 'SMTP_HOST',
				),
				'port'       => array(
					'label'    => __( 'Port', 'smtp' ),
					'type'     => 'number',
					'default'  => 587,
					'constant' => 'SMTP_PORT',
				),
				'encryption' => array(
					'label'    => __( 'Encryption', 'smtp' ),
					'type'     => 'select',
					'default'  => 'tls',
					'constant' => 'SMTP_ENCRYPTION',
					'options'  => array(
						'none' => __( 'None', 'smtp' ),
						'ssl'  => __( 'SSL', 'smtp' ),
						'tls'  => __( 'TLS (STARTTLS)', 'smtp' ),
					),
				),
				'auth'       => array(
					'label'       => __( 'Authentication', 'smtp' ),
					'type'        => 'checkbox',
					'default'     => true,
					'description' => __( 'Use a username and password to authenticate.', 'smtp' ),
				),
				'username'   => array(
					'label'    => __( 'Username', 'smtp' ),
					'type'     => 'text',
					'constant' => 'SMTP_USER',
				),
				'password'   => array(
					'label'    => __( 'Password', 'smtp' ),
					'type'     => 'password',
					'constant' => 'SMTP_PASSWORD',
				),
			),
			'configure' => array( $this, 'configure_generic' ),
		);
	}

	/**
	 * Configures PHPMailer for the Generic SMTP provider.
	 *
	 * @since 1.2.0
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 * @param array                          $values    Resolved field values.
	 */
	public function configure_generic( $phpmailer, array $values ) {
		// PHPMailer property names are not snake_case.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$phpmailer->isSMTP();
		$phpmailer->Host = $values['host'];
		$phpmailer->Port = (int) $values['port'];

		if ( 'none' === $values['encryption'] ) {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		} else {
			$phpmailer->SMTPSecure = $values['encryption'];
		}

		$phpmailer->SMTPAuth = (bool) $values['auth'];
		if ( $phpmailer->SMTPAuth ) {
			$phpmailer->Username = $values['username'];
			$phpmailer->Password = $values['password'];
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}
}
