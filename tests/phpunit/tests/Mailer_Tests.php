<?php
/**
 * Tests for the Mailer class.
 *
 * @package smtp
 */

use WordPress\SMTP\Credentials;
use WordPress\SMTP\Mailer;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Settings;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;
use WordPress\SMTP\Test_Utils\Traits\With_PHPMailer;

class Mailer_Tests extends WP_UnitTestCase {
	use With_Log_Table;
	use With_PHPMailer;

	/**
	 * @var Mailer
	 */
	private $mailer;

	public function set_up() {
		parent::set_up();

		$this->create_log_table();

		$registry     = new Provider_Registry();
		$this->mailer = new Mailer( new Settings( $registry, new Credentials() ), $registry );
	}

	private function configure( array $extra = array() ) {
		update_option(
			Settings::OPTION,
			array_merge(
				array(
					'provider' => 'smtp',
					'host'     => 'smtp.example.com',
				),
				$extra
			)
		);
	}

	private function default_from_email(): string {
		return 'wordpress@' . wp_parse_url( network_home_url(), PHP_URL_HOST );
	}

	public function test_add_hooks_registers_the_mail_hooks() {
		$mailer = new Mailer( new Settings( new Provider_Registry(), new Credentials() ), new Provider_Registry() );
		$mailer->add_hooks();

		$this->assertSame( 10, has_action( 'phpmailer_init', array( $mailer, 'configure' ) ) );
		$this->assertSame( PHP_INT_MAX, has_filter( 'wp_mail_from', array( $mailer, 'filter_from_email' ) ) );
		$this->assertSame( PHP_INT_MAX, has_filter( 'wp_mail_from_name', array( $mailer, 'filter_from_name' ) ) );
	}

	public function test_configure_does_nothing_until_a_host_is_set() {
		$phpmailer = $this->get_phpmailer();

		$this->mailer->configure( $phpmailer );

		$this->assertSame( 'mail', $phpmailer->Mailer );
		$this->assertSame( 'localhost', $phpmailer->Host );
	}

	public function test_configure_applies_the_provider_settings() {
		$this->configure(
			array(
				'port'       => 465,
				'encryption' => 'ssl',
				'auth'       => true,
				'username'   => 'user',
			)
		);
		( new Credentials() )->set( 'password', 'secret' );

		$phpmailer = $this->get_phpmailer();
		$this->mailer->configure( $phpmailer );

		$this->assertSame( 'smtp', $phpmailer->Mailer );
		$this->assertSame( 'smtp.example.com', $phpmailer->Host );
		$this->assertSame( 465, $phpmailer->Port );
		$this->assertSame( 'ssl', $phpmailer->SMTPSecure );
		$this->assertSame( 'user', $phpmailer->Username );
		$this->assertSame( 'secret', $phpmailer->Password );
	}

	public function test_configure_calls_the_provider_with_the_resolved_values() {
		$received = null;

		add_filter(
			'smtp_providers',
			static function ( $providers ) use ( &$received ) {
				$providers['custom'] = array(
					'label'     => 'Custom',
					'required'  => array( 'api_user' ),
					'fields'    => array(
						'api_user' => array( 'label' => 'API user' ),
					),
					'configure' => static function ( $phpmailer, $values ) use ( &$received ) {
						$received = $values;
					},
				);

				return $providers;
			}
		);
		update_option(
			Settings::OPTION,
			array(
				'provider' => 'custom',
				'api_user' => 'abc',
			)
		);

		$this->mailer->configure( $this->get_phpmailer() );

		$this->assertSame( array( 'api_user' => 'abc' ), $received );
	}

	public function test_from_email_is_untouched_when_not_configured() {
		update_option( Settings::OPTION, array( 'from_email' => 'team@example.com' ) );

		$this->assertSame( 'someone@else.com', $this->mailer->filter_from_email( 'someone@else.com' ) );
		$this->assertSame( $this->default_from_email(), $this->mailer->filter_from_email( $this->default_from_email() ) );
	}

	public function test_from_email_replaces_the_wordpress_default() {
		$this->configure( array( 'from_email' => 'team@example.com' ) );

		$this->assertSame( 'team@example.com', $this->mailer->filter_from_email( $this->default_from_email() ) );
	}

	public function test_from_email_keeps_an_address_set_by_someone_else() {
		$this->configure( array( 'from_email' => 'team@example.com' ) );

		$this->assertSame( 'someone@else.com', $this->mailer->filter_from_email( 'someone@else.com' ) );
	}

	public function test_force_from_overrides_any_address() {
		$this->configure(
			array(
				'from_email' => 'team@example.com',
				'force_from' => true,
			)
		);

		$this->assertSame( 'team@example.com', $this->mailer->filter_from_email( 'someone@else.com' ) );
	}

	public function test_from_email_is_untouched_when_empty_or_invalid() {
		$this->configure( array( 'from_email' => '' ) );
		$this->assertSame( 'someone@else.com', $this->mailer->filter_from_email( 'someone@else.com' ) );

		$this->configure(
			array(
				'from_email' => 'not-an-email',
				'force_from' => true,
			)
		);
		$this->assertSame( 'someone@else.com', $this->mailer->filter_from_email( 'someone@else.com' ) );
	}

	public function test_from_name_is_untouched_when_not_configured() {
		update_option( Settings::OPTION, array( 'from_name' => 'Team' ) );

		$this->assertSame( 'WordPress', $this->mailer->filter_from_name( 'WordPress' ) );
	}

	public function test_from_name_replaces_the_wordpress_default() {
		$this->configure( array( 'from_name' => 'Team' ) );

		$this->assertSame( 'Team', $this->mailer->filter_from_name( 'WordPress' ) );
	}

	public function test_from_name_keeps_a_name_set_by_someone_else() {
		$this->configure( array( 'from_name' => 'Team' ) );

		$this->assertSame( 'Other', $this->mailer->filter_from_name( 'Other' ) );
	}

	public function test_force_from_overrides_any_name() {
		$this->configure(
			array(
				'from_name'  => 'Team',
				'force_from' => true,
			)
		);

		$this->assertSame( 'Team', $this->mailer->filter_from_name( 'Other' ) );
	}

	public function test_from_name_is_untouched_when_empty() {
		$this->configure(
			array(
				'from_name'  => '',
				'force_from' => true,
			)
		);

		$this->assertSame( 'Other', $this->mailer->filter_from_name( 'Other' ) );
	}

	public function test_wp_mail_uses_the_configured_transport_and_sender() {
		$this->configure(
			array(
				'from_email' => 'team@example.com',
				'from_name'  => 'Team',
			)
		);

		wp_mail( 'to@example.org', 'Subject', 'Body' );

		$mailer = tests_retrieve_phpmailer_instance();

		$this->assertSame( 'smtp', $mailer->Mailer );
		$this->assertSame( 'smtp.example.com', $mailer->Host );
		$this->assertSame( 'team@example.com', $mailer->From );
		$this->assertSame( 'Team', $mailer->FromName );
	}
}
