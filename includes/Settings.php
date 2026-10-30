<?php
/**
 * Class WordPress\SMTP\Settings
 *
 * @package smtp
 */

namespace WordPress\SMTP;

/**
 * Reads, resolves and sanitizes the plugin settings.
 *
 * Values defined through constants always win over saved values.
 *
 * @since 1.2.0
 */
class Settings {

	/**
	 * Name of the option that stores the settings.
	 *
	 * @since 1.2.0
	 */
	const OPTION = 'smtp_settings';

	/**
	 * Provider registry.
	 *
	 * @since 1.2.0
	 * @var Provider_Registry
	 */
	private $registry;

	/**
	 * Credentials storage.
	 *
	 * @since 1.2.0
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Provider_Registry $registry    Provider registry.
	 * @param Credentials       $credentials Credentials storage.
	 */
	public function __construct( Provider_Registry $registry, Credentials $credentials ) {
		$this->registry    = $registry;
		$this->credentials = $credentials;
	}

	/**
	 * Returns the slug of the active provider.
	 *
	 * @since 1.2.0
	 *
	 * @return string Provider slug.
	 */
	public function get_provider_slug(): string {
		$options = $this->get_saved_options();
		$slug    = isset( $options['provider'] ) && is_string( $options['provider'] ) ? $options['provider'] : '';

		if ( '' !== $slug && null !== $this->registry->get( $slug ) ) {
			return $slug;
		}

		return $this->registry->get_default_slug();
	}

	/**
	 * Returns the normalized fields of a provider plus the general fields.
	 *
	 * @since 1.2.0
	 *
	 * @param string $slug Provider slug. Defaults to the active provider.
	 * @return array[] Fields keyed by field key.
	 */
	public function get_fields( string $slug = '' ): array {
		if ( '' === $slug ) {
			$slug = $this->get_provider_slug();
		}

		$provider = $this->registry->get( $slug );
		$fields   = $provider ? $provider['fields'] : array();

		$fields = array_merge( $fields, $this->get_general_fields() );

		foreach ( $fields as $key => $field ) {
			$fields[ $key ] = wp_parse_args(
				is_array( $field ) ? $field : array(),
				array(
					'label'       => $key,
					'type'        => 'text',
					'default'     => '',
					'options'     => array(),
					'description' => '',
					'constant'    => '',
				)
			);
		}

		return $fields;
	}

	/**
	 * Returns the resolved value of a field.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key Field key.
	 * @return string|int|bool Field value, or an empty string for unknown fields.
	 */
	public function get( string $key ) {
		$fields = $this->get_fields();

		if ( ! isset( $fields[ $key ] ) ) {
			return '';
		}

		return $this->resolve( $key, $fields[ $key ] );
	}

	/**
	 * Returns the resolved values of the active provider fields.
	 *
	 * @since 1.2.0
	 *
	 * @return array Values keyed by field key.
	 */
	public function get_provider_values(): array {
		$provider = $this->registry->get( $this->get_provider_slug() );
		$fields   = $this->get_fields();
		$values   = array();

		if ( ! $provider ) {
			return $values;
		}

		foreach ( array_keys( $provider['fields'] ) as $key ) {
			$values[ $key ] = $this->resolve( $key, $fields[ $key ] );
		}

		return $values;
	}

	/**
	 * Whether the active provider has everything it needs to send mail.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True when mail should be sent through the provider.
	 */
	public function is_configured(): bool {
		$provider = $this->registry->get( $this->get_provider_slug() );

		if ( ! $provider ) {
			return false;
		}

		$values = $this->get_provider_values();
		foreach ( $provider['required'] as $key ) {
			if ( empty( $values[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the value of a field is defined by a constant.
	 *
	 * @since 1.2.0
	 *
	 * @param array $field Normalized field.
	 * @return bool True when locked by a constant.
	 */
	public function is_locked( array $field ): bool {
		return '' !== $field['constant'] && defined( $field['constant'] );
	}

	/**
	 * Sanitizes the submitted settings.
	 *
	 * Secrets are never written to the settings option, they go to the credentials storage.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $input Submitted settings.
	 * @return array Sanitized settings.
	 */
	public function sanitize( $input ): array {
		$input     = is_array( $input ) ? $input : array();
		$providers = $this->registry->get_all();
		$slug      = isset( $input['provider'] ) && is_string( $input['provider'] ) ? $input['provider'] : '';

		if ( ! isset( $providers[ $slug ] ) ) {
			$slug = $this->get_provider_slug();
		}

		$clean = array( 'provider' => $slug );

		foreach ( $this->get_fields( $slug ) as $key => $field ) {
			if ( $this->is_locked( $field ) ) {
				continue;
			}

			$raw = $input[ $key ] ?? null;

			switch ( $field['type'] ) {
				case 'checkbox':
					$clean[ $key ] = ! empty( $raw );
					break;

				case 'password':
					// An empty submission keeps the current secret.
					if ( is_string( $raw ) && '' !== $raw ) {
						$this->credentials->set( $key, $raw );
					}
					break;

				case 'select':
					$clean[ $key ] = is_string( $raw ) && isset( $field['options'][ $raw ] ) ? $raw : $field['default'];
					break;

				case 'number':
					$number        = absint( $raw );
					$clean[ $key ] = $number > 0 ? $number : $field['default'];
					break;

				case 'email':
					$clean[ $key ] = is_string( $raw ) ? sanitize_email( $raw ) : '';
					break;

				default:
					$clean[ $key ] = is_string( $raw ) ? sanitize_text_field( $raw ) : '';
			}
		}

		return $clean;
	}

	/**
	 * Returns the fields shared by every provider.
	 *
	 * @since 1.2.0
	 *
	 * @return array[] Fields keyed by field key.
	 */
	private function get_general_fields(): array {
		return array(
			'from_email' => array(
				'label'       => __( 'From email', 'smtp' ),
				'type'        => 'email',
				'constant'    => 'SMTP_FROM_EMAIL',
				'description' => __( 'Leave empty to keep the address chosen by WordPress or other plugins.', 'smtp' ),
			),
			'from_name'  => array(
				'label' => __( 'From name', 'smtp' ),
				'type'  => 'text',
			),
			'force_from' => array(
				'label'       => __( 'Force from', 'smtp' ),
				'type'        => 'checkbox',
				'default'     => false,
				'description' => __( 'Always use the From email and name above, even when another plugin sets its own.', 'smtp' ),
			),
		);
	}

	/**
	 * Resolves the value of a field: constant, then secret or saved option, then default.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key   Field key.
	 * @param array  $field Normalized field.
	 * @return string|int|bool Field value.
	 */
	private function resolve( string $key, array $field ) {
		if ( $this->is_locked( $field ) ) {
			return $this->cast( constant( $field['constant'] ), $field );
		}

		if ( 'password' === $field['type'] ) {
			return $this->credentials->get( $key );
		}

		$options = $this->get_saved_options();
		if ( array_key_exists( $key, $options ) ) {
			return $this->cast( $options[ $key ], $field );
		}

		return $this->cast( $field['default'], $field );
	}

	/**
	 * Casts a value to the type of its field.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $value Raw value.
	 * @param array $field Normalized field.
	 * @return string|int|bool Cast value.
	 */
	private function cast( $value, array $field ) {
		switch ( $field['type'] ) {
			case 'checkbox':
				return (bool) $value;
			case 'number':
				return (int) $value;
			default:
				return is_scalar( $value ) ? (string) $value : '';
		}
	}

	/**
	 * Returns the saved settings option.
	 *
	 * @since 1.2.0
	 *
	 * @return array Saved settings.
	 */
	private function get_saved_options(): array {
		$options = get_option( self::OPTION, array() );

		return is_array( $options ) ? $options : array();
	}
}
