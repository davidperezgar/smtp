<?php
/**
 * Tests for the Migration class.
 *
 * @package smtp
 */

use WordPress\SMTP\Credentials;
use WordPress\SMTP\Migration;
use WordPress\SMTP\Plugin_Main;
use WordPress\SMTP\Settings;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;

class Migration_Tests extends WP_UnitTestCase {
	use With_Log_Table;

	/**
	 * @var Credentials
	 */
	private $credentials;

	public function set_up() {
		parent::set_up();

		// The plugin already ran its own migration while WordPress was loading.
		delete_option( Migration::DONE_OPTION );
		delete_option( Migration::NOTICE_OPTION );
		delete_option( Settings::OPTION );
		delete_option( Credentials::OPTION );

		$this->credentials = new Credentials();
	}

	public function tear_down() {
		unset( $_GET['page'], $_GET['tab'] );

		parent::tear_down();
	}

	private function legacy( array $overrides = array() ): array {
		return array_merge(
			array(
				'host'        => 'smtp.gmail.com',
				'port'        => '465',
				'smtp_secure' => 'ssl',
				'username'    => 'me@gmail.com',
				'password'    => 'encrypted-value',
			),
			$overrides
		);
	}

	private function migration( ?callable $decrypter = null ): Migration {
		return new Migration(
			$this->credentials,
			$decrypter ? $decrypter : static function () {
				return 'my-password';
			}
		);
	}

	private function render_notice( Migration $migration ): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		$migration->render_notice();

		return ob_get_clean();
	}

	public function test_add_hooks_runs_the_migration_on_init_and_registers_the_notice() {
		set_current_screen( 'dashboard' );

		$migration = $this->migration();
		$migration->add_hooks();

		$this->assertSame( 0, has_action( 'init', array( $migration, 'maybe_migrate' ) ) );
		$this->assertSame( 10, has_action( 'admin_notices', array( $migration, 'render_notice' ) ) );
	}

	public function test_the_plugin_runs_the_migration_when_it_loads() {
		$this->assertNotFalse( has_action( 'init' ) );
		$this->assertSame( '1', get_option( Migration::DONE_OPTION, '1' ) );
	}

	public function test_the_legacy_settings_are_imported() {
		update_option( Migration::LEGACY_OPTION, $this->legacy() );

		$this->assertTrue( $this->migration()->maybe_migrate() );

		$settings = get_option( Settings::OPTION );

		$this->assertSame( 'smtp', $settings['provider'] );
		$this->assertSame( 'smtp.gmail.com', $settings['host'] );
		$this->assertSame( 465, $settings['port'] );
		$this->assertSame( 'ssl', $settings['encryption'] );
		$this->assertTrue( $settings['auth'] );
		$this->assertSame( 'me@gmail.com', $settings['username'] );
	}

	public function test_the_password_is_decrypted_and_stored_as_a_secret() {
		update_option( Migration::LEGACY_OPTION, $this->legacy() );

		$received = null;
		$this->migration(
			static function ( $encrypted ) use ( &$received ) {
				$received = $encrypted;

				return 'my-password';
			}
		)->maybe_migrate();

		$this->assertSame( 'encrypted-value', $received );
		$this->assertSame( 'my-password', $this->credentials->get( 'password' ) );
		$this->assertArrayNotHasKey( 'password', get_option( Settings::OPTION ) );
	}

	public function test_the_imported_settings_make_the_plugin_ready_to_send() {
		update_option( Migration::LEGACY_OPTION, $this->legacy() );

		$this->migration()->maybe_migrate();

		$settings = new Settings( new WordPress\SMTP\Provider_Registry(), $this->credentials );

		$this->assertTrue( $settings->is_configured() );
		$this->assertSame( 'my-password', $settings->get( 'password' ) );
	}

	public function test_mail_keeps_being_sent_as_the_first_user() {
		update_option( Migration::LEGACY_OPTION, $this->legacy() );

		$this->migration()->maybe_migrate();

		$admin    = get_userdata( 1 );
		$settings = get_option( Settings::OPTION );

		$this->assertSame( $admin->user_email, $settings['from_email'] );
		$this->assertSame( $admin->display_name, $settings['from_name'] );
		$this->assertTrue( $settings['force_from'] );
	}

	public function test_encryption_none_and_unknown_values() {
		update_option( Migration::LEGACY_OPTION, $this->legacy( array( 'smtp_secure' => '' ) ) );
		$this->migration()->maybe_migrate();
		$this->assertSame( 'none', get_option( Settings::OPTION )['encryption'] );

		delete_option( Migration::DONE_OPTION );
		delete_option( Settings::OPTION );
		update_option( Migration::LEGACY_OPTION, $this->legacy( array( 'smtp_secure' => 'starttls' ) ) );
		$this->migration()->maybe_migrate();
		$this->assertSame( 'none', get_option( Settings::OPTION )['encryption'] );
	}

	public function test_tls_is_kept() {
		update_option( Migration::LEGACY_OPTION, $this->legacy( array( 'smtp_secure' => 'tls' ) ) );

		$this->migration()->maybe_migrate();

		$this->assertSame( 'tls', get_option( Settings::OPTION )['encryption'] );
	}

	public function test_an_invalid_port_falls_back_to_the_legacy_default() {
		update_option( Migration::LEGACY_OPTION, $this->legacy( array( 'port' => 'abc' ) ) );

		$this->migration()->maybe_migrate();

		$this->assertSame( 25, get_option( Settings::OPTION )['port'] );
	}

	public function test_a_scheme_is_removed_from_the_host() {
		update_option( Migration::LEGACY_OPTION, $this->legacy( array( 'host' => 'http://smtp.example.com' ) ) );

		$this->migration()->maybe_migrate();

		$this->assertSame( 'smtp.example.com', get_option( Settings::OPTION )['host'] );
	}

	public function test_without_a_username_authentication_is_disabled() {
		update_option(
			Migration::LEGACY_OPTION,
			$this->legacy(
				array(
					'username' => '',
					'password' => '',
				)
			)
		);

		$this->migration()->maybe_migrate();

		$settings = get_option( Settings::OPTION );

		$this->assertFalse( $settings['auth'] );
		$this->assertSame( '', $settings['username'] );
		$this->assertSame( Migration::NOTICE_MIGRATED, get_option( Migration::NOTICE_OPTION ) );
	}

	public function test_a_legacy_plugin_that_was_never_configured_is_not_imported() {
		update_option(
			Migration::LEGACY_OPTION,
			array(
				'host'        => 'http://localhost',
				'port'        => '25',
				'smtp_secure' => '',
				'username'    => '',
				'password'    => '',
			)
		);

		$this->assertFalse( $this->migration()->maybe_migrate() );
		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Migration::NOTICE_OPTION ) );
	}

	public function test_an_empty_host_is_not_imported() {
		update_option( Migration::LEGACY_OPTION, $this->legacy( array( 'host' => '  ' ) ) );

		$this->assertFalse( $this->migration()->maybe_migrate() );
		$this->assertFalse( get_option( Settings::OPTION ) );
	}

	public function test_nothing_happens_without_legacy_settings() {
		$this->assertFalse( $this->migration()->maybe_migrate() );
		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertSame( '1', get_option( Migration::DONE_OPTION ) );
	}

	public function test_a_corrupted_legacy_option_is_ignored() {
		update_option( Migration::LEGACY_OPTION, 'garbage' );

		$this->assertFalse( $this->migration()->maybe_migrate() );
		$this->assertFalse( get_option( Settings::OPTION ) );
	}

	public function test_existing_settings_are_never_overwritten() {
		update_option( Migration::LEGACY_OPTION, $this->legacy() );
		update_option( Settings::OPTION, array( 'host' => 'already.configured.example' ) );

		$this->assertFalse( $this->migration()->maybe_migrate() );
		$this->assertSame( array( 'host' => 'already.configured.example' ), get_option( Settings::OPTION ) );
	}

	public function test_the_migration_only_runs_once() {
		update_option( Migration::LEGACY_OPTION, $this->legacy() );

		$this->assertTrue( $this->migration()->maybe_migrate() );

		delete_option( Settings::OPTION );

		$this->assertFalse( $this->migration()->maybe_migrate() );
		$this->assertFalse( get_option( Settings::OPTION ) );
	}

	public function test_the_legacy_option_is_left_untouched() {
		update_option( Migration::LEGACY_OPTION, $this->legacy() );

		$this->migration()->maybe_migrate();

		$this->assertSame( $this->legacy(), get_option( Migration::LEGACY_OPTION ) );
	}

	public function test_a_password_that_cannot_be_decrypted_asks_the_user_to_enter_it_again() {
		update_option( Migration::LEGACY_OPTION, $this->legacy() );

		$this->assertTrue(
			$this->migration(
				static function () {
					return '';
				}
			)->maybe_migrate()
		);

		$this->assertSame( '', $this->credentials->get( 'password' ) );
		$this->assertSame( Migration::NOTICE_PASSWORD_MISSING, get_option( Migration::NOTICE_OPTION ) );
	}

	public function test_a_decrypter_returning_something_else_is_treated_as_a_failure() {
		update_option( Migration::LEGACY_OPTION, $this->legacy() );

		$this->migration( '__return_false' )->maybe_migrate();

		$this->assertSame( '', $this->credentials->get( 'password' ) );
	}

	public function test_mcrypt_decryption_is_skipped_when_the_extension_is_missing() {
		if ( function_exists( 'mcrypt_decrypt' ) ) {
			$this->markTestSkipped( 'The mcrypt extension is available.' );
		}

		$this->assertSame( '', ( new Migration( $this->credentials ) )->decrypt_with_mcrypt( 'anything' ) );
	}

	public function test_activation_imports_the_legacy_settings() {
		update_option( Migration::LEGACY_OPTION, $this->legacy( array( 'password' => '' ) ) );

		Plugin_Main::activate();

		$this->assertSame( 'smtp.gmail.com', get_option( Settings::OPTION )['host'] );
	}

	public function test_the_migrated_notice_links_to_the_settings() {
		update_option( Migration::NOTICE_OPTION, Migration::NOTICE_MIGRATED );

		$html = $this->render_notice( $this->migration() );

		$this->assertStringContainsString( 'imported from the previous version', $html );
		$this->assertStringContainsString( 'tools.php?page=smtp', $html );
		$this->assertStringContainsString( 'notice-info', $html );
	}

	public function test_the_migrated_notice_goes_away_once_the_settings_page_is_visited() {
		update_option( Migration::NOTICE_OPTION, Migration::NOTICE_MIGRATED );
		$_GET['page'] = 'smtp';
		$_GET['tab']  = 'settings';

		$this->render_notice( $this->migration() );

		$this->assertFalse( get_option( Migration::NOTICE_OPTION ) );
	}

	public function test_the_migrated_notice_stays_when_another_tab_is_visited() {
		update_option( Migration::NOTICE_OPTION, Migration::NOTICE_MIGRATED );
		$_GET['page'] = 'smtp';
		$_GET['tab']  = 'log';

		$this->render_notice( $this->migration() );

		$this->assertSame( Migration::NOTICE_MIGRATED, get_option( Migration::NOTICE_OPTION ) );
	}

	public function test_the_notice_links_to_the_settings_tab() {
		update_option( Migration::NOTICE_OPTION, Migration::NOTICE_MIGRATED );

		$this->assertStringContainsString( 'tab=settings', $this->render_notice( $this->migration() ) );
	}

	public function test_the_migrated_notice_stays_on_other_pages() {
		update_option( Migration::NOTICE_OPTION, Migration::NOTICE_MIGRATED );

		$this->render_notice( $this->migration() );

		$this->assertSame( Migration::NOTICE_MIGRATED, get_option( Migration::NOTICE_OPTION ) );
	}

	public function test_the_password_notice_is_shown_until_a_password_is_saved() {
		update_option( Migration::NOTICE_OPTION, Migration::NOTICE_PASSWORD_MISSING );

		$html = $this->render_notice( $this->migration() );

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'password could not be', $html );

		$this->credentials->set( 'password', 'new-password' );

		$this->assertSame( '', $this->render_notice( $this->migration() ) );
		$this->assertFalse( get_option( Migration::NOTICE_OPTION ) );
	}

	public function test_no_notice_is_shown_without_the_capability() {
		update_option( Migration::NOTICE_OPTION, Migration::NOTICE_MIGRATED );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		$this->migration()->render_notice();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_no_notice_is_shown_when_there_is_nothing_to_report() {
		$this->assertSame( '', $this->render_notice( $this->migration() ) );
	}
}
