<?php
/**
 * Tests for the Settings class.
 *
 * @package smtp
 */

use WordPress\SMTP\Credentials;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Settings;

class Settings_Tests extends WP_UnitTestCase {

	/**
	 * @var Settings
	 */
	private $settings;

	/**
	 * @var Credentials
	 */
	private $credentials;

	public function set_up() {
		parent::set_up();

		$this->credentials = new Credentials();
		$this->settings    = new Settings( new Provider_Registry(), $this->credentials );
	}

	private function save( array $options ) {
		update_option( Settings::OPTION, $options );
	}

	private function add_locked_provider() {
		if ( ! defined( 'SMTP_TESTS_LOCKED_TOKEN' ) ) {
			define( 'SMTP_TESTS_LOCKED_TOKEN', 'from-constant' );
		}

		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['locked'] = array(
					'label'     => 'Locked',
					'required'  => array( 'token' ),
					'fields'    => array(
						'token' => array(
							'label'    => 'Token',
							'type'     => 'text',
							'constant' => 'SMTP_TESTS_LOCKED_TOKEN',
						),
					),
					'configure' => '__return_null',
				);

				return $providers;
			}
		);
	}

	public function test_provider_slug_defaults_to_smtp() {
		$this->assertSame( 'smtp', $this->settings->get_provider_slug() );
	}

	public function test_provider_slug_uses_the_saved_provider() {
		$this->add_locked_provider();
		$this->save( array( 'provider' => 'locked' ) );

		$this->assertSame( 'locked', $this->settings->get_provider_slug() );
	}

	public function test_provider_slug_falls_back_when_the_saved_provider_is_gone() {
		$this->save( array( 'provider' => 'removed' ) );

		$this->assertSame( 'smtp', $this->settings->get_provider_slug() );
	}

	public function test_fields_include_provider_and_general_fields() {
		$keys = array_keys( $this->settings->get_fields() );

		$this->assertSame(
			array( 'host', 'port', 'encryption', 'auth', 'username', 'password', 'from_email', 'from_name', 'force_from' ),
			$keys
		);
	}

	public function test_fields_are_normalized() {
		$field = $this->settings->get_fields()['from_name'];

		$this->assertSame( 'text', $field['type'] );
		$this->assertSame( '', $field['default'] );
		$this->assertSame( array(), $field['options'] );
		$this->assertSame( '', $field['constant'] );
	}

	public function test_fields_of_an_unknown_provider_are_only_the_general_ones() {
		$this->assertSame(
			array( 'from_email', 'from_name', 'force_from' ),
			array_keys( $this->settings->get_fields( 'unknown' ) )
		);
	}

	public function test_get_returns_defaults_when_nothing_is_saved() {
		$this->assertSame( '', $this->settings->get( 'host' ) );
		$this->assertSame( 587, $this->settings->get( 'port' ) );
		$this->assertSame( 'tls', $this->settings->get( 'encryption' ) );
		$this->assertTrue( $this->settings->get( 'auth' ) );
		$this->assertFalse( $this->settings->get( 'force_from' ) );
	}

	public function test_get_returns_saved_values_cast_to_the_field_type() {
		$this->save(
			array(
				'host'       => 'smtp.example.com',
				'port'       => '2525',
				'auth'       => 0,
				'force_from' => 1,
			)
		);

		$this->assertSame( 'smtp.example.com', $this->settings->get( 'host' ) );
		$this->assertSame( 2525, $this->settings->get( 'port' ) );
		$this->assertFalse( $this->settings->get( 'auth' ) );
		$this->assertTrue( $this->settings->get( 'force_from' ) );
	}

	public function test_get_reads_secrets_from_the_credentials_storage() {
		$this->credentials->set( 'password', 'secret' );

		$this->assertSame( 'secret', $this->settings->get( 'password' ) );
	}

	public function test_get_returns_an_empty_string_for_an_unknown_field() {
		$this->assertSame( '', $this->settings->get( 'unknown' ) );
	}

	public function test_get_survives_a_corrupted_option() {
		update_option( Settings::OPTION, 'not-an-array' );

		$this->assertSame( 587, $this->settings->get( 'port' ) );
	}

	public function test_constants_override_saved_values() {
		$this->add_locked_provider();
		$this->save(
			array(
				'provider' => 'locked',
				'token'    => 'from-option',
			)
		);

		$this->assertSame( 'from-constant', $this->settings->get( 'token' ) );
	}

	public function test_is_locked_detects_defined_constants() {
		$this->add_locked_provider();

		$fields = $this->settings->get_fields( 'locked' );

		$this->assertTrue( $this->settings->is_locked( $fields['token'] ) );
		$this->assertFalse( $this->settings->is_locked( $fields['from_name'] ) );
	}

	public function test_is_locked_ignores_undefined_constants() {
		$field = $this->settings->get_fields()['host'];

		$this->assertSame( 'SMTP_HOST', $field['constant'] );
		$this->assertFalse( $this->settings->is_locked( $field ) );
	}

	public function test_is_configured_requires_the_required_fields() {
		$this->assertFalse( $this->settings->is_configured() );

		$this->save( array( 'host' => 'smtp.example.com' ) );

		$this->assertTrue( $this->settings->is_configured() );
	}

	public function test_is_configured_is_false_without_providers() {
		add_filter( 'smtp_providers', '__return_empty_array' );

		$this->save( array( 'host' => 'smtp.example.com' ) );

		$this->assertFalse( $this->settings->is_configured() );
	}

	public function test_is_configured_is_true_when_a_constant_satisfies_the_requirement() {
		$this->add_locked_provider();
		$this->save( array( 'provider' => 'locked' ) );

		$this->assertTrue( $this->settings->is_configured() );
	}

	public function test_provider_values_only_include_the_provider_fields() {
		$this->save( array( 'host' => 'smtp.example.com' ) );

		$this->assertSame(
			array( 'host', 'port', 'encryption', 'auth', 'username', 'password' ),
			array_keys( $this->settings->get_provider_values() )
		);
	}

	public function test_provider_values_are_empty_without_providers() {
		add_filter( 'smtp_providers', '__return_empty_array' );

		$this->assertSame( array(), $this->settings->get_provider_values() );
	}

	public function test_sanitize_cleans_every_field_type() {
		$clean = $this->settings->sanitize(
			array(
				'provider'   => 'smtp',
				'host'       => ' <b>smtp.example.com</b> ',
				'port'       => '2525abc',
				'encryption' => 'ssl',
				'auth'       => '1',
				'username'   => 'user',
				'from_email' => 'Team@Example.com<script>',
				'from_name'  => '<i>Team</i>',
				'force_from' => '1',
			)
		);

		$this->assertSame( 'smtp', $clean['provider'] );
		$this->assertSame( 'smtp.example.com', $clean['host'] );
		$this->assertSame( 2525, $clean['port'] );
		$this->assertSame( 'ssl', $clean['encryption'] );
		$this->assertTrue( $clean['auth'] );
		$this->assertSame( 'user', $clean['username'] );
		$this->assertStringNotContainsString( '<', $clean['from_email'] );
		$this->assertSame( 'Team', $clean['from_name'] );
		$this->assertTrue( $clean['force_from'] );
	}

	public function test_sanitize_unchecked_checkboxes_are_false() {
		$clean = $this->settings->sanitize( array( 'provider' => 'smtp' ) );

		$this->assertFalse( $clean['auth'] );
		$this->assertFalse( $clean['force_from'] );
	}

	public function test_sanitize_falls_back_to_defaults_for_invalid_values() {
		$clean = $this->settings->sanitize(
			array(
				'provider'   => 'smtp',
				'port'       => '0',
				'encryption' => 'evil',
			)
		);

		$this->assertSame( 587, $clean['port'] );
		$this->assertSame( 'tls', $clean['encryption'] );
	}

	public function test_sanitize_falls_back_to_the_current_provider_when_unknown() {
		$clean = $this->settings->sanitize( array( 'provider' => 'unknown' ) );

		$this->assertSame( 'smtp', $clean['provider'] );
	}

	public function test_sanitize_accepts_non_array_input() {
		$clean = $this->settings->sanitize( 'garbage' );

		$this->assertSame( 'smtp', $clean['provider'] );
		$this->assertSame( '', $clean['host'] );
	}

	public function test_sanitize_ignores_non_string_text_values() {
		$clean = $this->settings->sanitize(
			array(
				'provider' => 'smtp',
				'host'     => array( 'nested' ),
			)
		);

		$this->assertSame( '', $clean['host'] );
	}

	public function test_sanitize_stores_the_password_in_the_credentials_storage_only() {
		$clean = $this->settings->sanitize(
			array(
				'provider' => 'smtp',
				'password' => 'p@ss <word> %20',
			)
		);

		$this->assertArrayNotHasKey( 'password', $clean );
		$this->assertSame( 'p@ss <word> %20', $this->credentials->get( 'password' ) );
	}

	public function test_sanitize_keeps_the_current_password_when_the_field_is_empty() {
		$this->credentials->set( 'password', 'current' );

		$this->settings->sanitize(
			array(
				'provider' => 'smtp',
				'password' => '',
			)
		);

		$this->assertSame( 'current', $this->credentials->get( 'password' ) );
	}

	public function test_sanitize_skips_fields_locked_by_a_constant() {
		$this->add_locked_provider();

		$clean = $this->settings->sanitize(
			array(
				'provider' => 'locked',
				'token'    => 'attempt',
			)
		);

		$this->assertArrayNotHasKey( 'token', $clean );
	}

	public function test_sanitize_is_idempotent() {
		$input = array(
			'provider'   => 'smtp',
			'host'       => 'smtp.example.com',
			'port'       => '465',
			'encryption' => 'ssl',
			'auth'       => '1',
			'from_email' => 'team@example.com',
			'password'   => 'secret',
		);

		$first  = $this->settings->sanitize( $input );
		$second = $this->settings->sanitize( $first );

		$this->assertSame( $first, $second );
		$this->assertSame( 'secret', $this->credentials->get( 'password' ) );
	}
}
