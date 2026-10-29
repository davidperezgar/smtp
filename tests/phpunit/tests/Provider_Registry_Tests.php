<?php
/**
 * Tests for the Provider_Registry class.
 *
 * @package smtp
 */

use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Test_Utils\Traits\With_PHPMailer;

class Provider_Registry_Tests extends WP_UnitTestCase {
	use With_PHPMailer;

	/**
	 * @var Provider_Registry
	 */
	private $registry;

	public function set_up() {
		parent::set_up();

		$this->registry = new Provider_Registry();
	}

	public function test_generic_smtp_is_registered_by_default() {
		$providers = $this->registry->get_all();

		$this->assertArrayHasKey( 'smtp', $providers );
		$this->assertSame( 'Generic SMTP', $providers['smtp']['label'] );
		$this->assertSame( array( 'host' ), $providers['smtp']['required'] );
		$this->assertTrue( is_callable( $providers['smtp']['configure'] ) );
	}

	public function test_generic_smtp_defines_the_expected_fields() {
		$fields = $this->registry->get( 'smtp' )['fields'];

		$this->assertSame(
			array( 'host', 'port', 'encryption', 'auth', 'username', 'password' ),
			array_keys( $fields )
		);
		$this->assertSame( 'SMTP_HOST', $fields['host']['constant'] );
		$this->assertSame( 'SMTP_PASSWORD', $fields['password']['constant'] );
		$this->assertSame( 'password', $fields['password']['type'] );
	}

	public function test_filter_can_add_a_provider() {
		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['custom'] = array(
					'label'     => 'Custom',
					'configure' => '__return_null',
				);

				return $providers;
			}
		);

		$provider = $this->registry->get( 'custom' );

		$this->assertIsArray( $provider );
		$this->assertSame( array(), $provider['fields'] );
		$this->assertSame( array(), $provider['required'] );
	}

	public function test_invalid_providers_are_discarded() {
		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['no-label']     = array( 'configure' => '__return_null' );
				$providers['no-configure'] = array( 'label' => 'No configure' );
				$providers['bad-callable'] = array(
					'label'     => 'Bad callable',
					'configure' => 'this_function_does_not_exist',
				);
				$providers['not-an-array'] = 'nope';
				$providers[0]              = array(
					'label'     => 'Numeric slug',
					'configure' => '__return_null',
				);

				return $providers;
			}
		);

		$this->assertSame( array( 'smtp' ), array_keys( $this->registry->get_all() ) );
	}

	public function test_filter_returning_a_non_array_leaves_no_providers() {
		add_filter( 'smtp_providers', '__return_false' );

		$this->assertSame( array(), $this->registry->get_all() );
		$this->assertSame( '', $this->registry->get_default_slug() );
	}

	public function test_get_returns_null_for_an_unknown_provider() {
		$this->assertNull( $this->registry->get( 'unknown' ) );
	}

	public function test_default_slug_is_smtp() {
		$this->assertSame( 'smtp', $this->registry->get_default_slug() );
	}

	public function test_default_slug_falls_back_to_the_first_provider() {
		add_filter(
			'smtp_providers',
			static function () {
				return array(
					'first'  => array(
						'label'     => 'First',
						'configure' => '__return_null',
					),
					'second' => array(
						'label'     => 'Second',
						'configure' => '__return_null',
					),
				);
			}
		);

		$this->assertSame( 'first', $this->registry->get_default_slug() );
	}

	public function test_configure_generic_with_authentication_and_ssl() {
		$phpmailer = $this->get_phpmailer();

		$this->registry->configure_generic(
			$phpmailer,
			array(
				'host'       => 'smtp.example.com',
				'port'       => '465',
				'encryption' => 'ssl',
				'auth'       => true,
				'username'   => 'user',
				'password'   => 'secret',
			)
		);

		$this->assertSame( 'smtp', $phpmailer->Mailer );
		$this->assertSame( 'smtp.example.com', $phpmailer->Host );
		$this->assertSame( 465, $phpmailer->Port );
		$this->assertSame( 'ssl', $phpmailer->SMTPSecure );
		$this->assertTrue( $phpmailer->SMTPAuth );
		$this->assertSame( 'user', $phpmailer->Username );
		$this->assertSame( 'secret', $phpmailer->Password );
	}

	public function test_configure_generic_without_encryption_disables_auto_tls() {
		$phpmailer = $this->get_phpmailer();

		$this->registry->configure_generic(
			$phpmailer,
			array(
				'host'       => 'localhost',
				'port'       => 25,
				'encryption' => 'none',
				'auth'       => false,
				'username'   => 'ignored',
				'password'   => 'ignored',
			)
		);

		$this->assertSame( '', $phpmailer->SMTPSecure );
		$this->assertFalse( $phpmailer->SMTPAutoTLS );
		$this->assertFalse( $phpmailer->SMTPAuth );
		$this->assertSame( '', $phpmailer->Username );
		$this->assertSame( '', $phpmailer->Password );
	}
}
