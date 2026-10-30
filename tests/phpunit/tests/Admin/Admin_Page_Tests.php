<?php
/**
 * Tests for the Admin_Page class.
 *
 * @package smtp
 */

use WordPress\SMTP\Admin\Admin_Page;
use WordPress\SMTP\Credentials;
use WordPress\SMTP\Log_Repository;
use WordPress\SMTP\Provider_Registry;
use WordPress\SMTP\Settings;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;

class Admin_Page_Tests extends WP_UnitTestCase {
	use With_Log_Table;

	/**
	 * @var Admin_Page
	 */
	private $admin_page;

	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$GLOBALS['title'] = 'SMTP';

		$registry         = new Provider_Registry();
		$this->admin_page = new Admin_Page(
			new Settings( $registry, new Credentials() ),
			$registry,
			new Log_Repository(),
			WP_SMTP_MAIN_FILE
		);

		$this->create_log_table();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		unset( $_GET['tab'], $_GET['status'], $GLOBALS['title'] );

		parent::tear_down();
	}

	private function render( string $tab = '' ): string {
		if ( '' !== $tab ) {
			$_GET['tab'] = $tab;
		}

		ob_start();
		$this->admin_page->render_page();

		return ob_get_clean();
	}

	public function test_add_hooks_registers_the_admin_hooks() {
		$this->admin_page->add_hooks();

		$this->assertSame( 10, has_action( 'admin_menu', array( $this->admin_page, 'add_menu' ) ) );
		$this->assertSame( 10, has_action( 'admin_init', array( $this->admin_page, 'register_settings' ) ) );
		$this->assertSame(
			10,
			has_filter( 'plugin_action_links_' . plugin_basename( WP_SMTP_MAIN_FILE ), array( $this->admin_page, 'add_action_link' ) )
		);
	}

	public function test_add_menu_adds_the_page_under_tools() {
		$this->admin_page->add_menu();

		$slugs = wp_list_pluck( $GLOBALS['submenu']['tools.php'], 2 );

		$this->assertContains( Admin_Page::PAGE_SLUG, $slugs );
	}

	public function test_add_action_link_prepends_a_settings_link() {
		$links = $this->admin_page->add_action_link( array( '<a href="#">Deactivate</a>' ) );

		$this->assertCount( 2, $links );
		$this->assertStringContainsString( 'tools.php?page=smtp', $links[0] );
		$this->assertStringContainsString( 'Deactivate', $links[1] );
	}

	public function test_register_settings_registers_the_option_and_fields() {
		$this->admin_page->register_settings();

		$this->assertArrayHasKey( Settings::OPTION, get_registered_settings() );

		$fields = array_keys( $GLOBALS['wp_settings_fields'][ Admin_Page::PAGE_SLUG ]['smtp_main'] );

		$this->assertSame(
			array( 'provider', 'host', 'port', 'encryption', 'auth', 'username', 'password', 'from_email', 'from_name', 'force_from' ),
			$fields
		);
	}

	public function test_the_setting_is_sanitized_through_the_settings_class() {
		$this->admin_page->register_settings();

		$clean = apply_filters(
			'sanitize_option_' . Settings::OPTION,
			array(
				'provider' => 'smtp',
				'port'     => '25',
			),
			Settings::OPTION,
			array()
		);

		$this->assertSame( 25, $clean['port'] );
	}

	public function test_render_page_shows_the_settings_tab_by_default() {
		$this->admin_page->register_settings();

		$html = $this->render();

		$this->assertStringContainsString( 'nav-tab-active', $html );
		$this->assertStringContainsString( 'name="smtp_settings[host]"', $html );
		$this->assertStringContainsString( 'name="smtp_settings[provider]"', $html );
	}

	private function get_tab_slugs( string $html ): array {
		preg_match_all( '/tab=([a-z]+)"\s+class="nav-tab/', $html, $matches );

		return $matches[1];
	}

	public function test_settings_is_the_first_tab_until_the_plugin_is_configured() {
		$html = $this->render();

		$this->assertSame( array( 'settings', 'log', 'test' ), $this->get_tab_slugs( $html ) );
	}

	public function test_the_log_is_the_first_tab_once_the_plugin_is_configured() {
		update_option( Settings::OPTION, array( 'host' => 'smtp.example.com' ) );
		$this->admin_page->register_settings();

		$html = $this->render();

		$this->assertSame( array( 'log', 'settings', 'test' ), $this->get_tab_slugs( $html ) );
		$this->assertStringContainsString( 'No emails logged yet.', $html );
		$this->assertStringNotContainsString( 'name="smtp_settings[host]"', $html );
	}

	public function test_an_unknown_tab_opens_the_first_tab_of_a_configured_plugin() {
		update_option( Settings::OPTION, array( 'host' => 'smtp.example.com' ) );

		$this->assertStringContainsString( 'No emails logged yet.', $this->render( 'unknown' ) );
	}

	public function test_the_settings_tab_stays_reachable_once_configured() {
		update_option( Settings::OPTION, array( 'host' => 'smtp.example.com' ) );
		$this->admin_page->register_settings();

		$this->assertStringContainsString( 'name="smtp_settings[host]"', $this->render( 'settings' ) );
	}

	public function test_saving_the_settings_returns_to_the_settings_tab() {
		$this->admin_page->register_settings();

		$html = $this->render( 'settings' );

		preg_match_all( '/name="_wp_http_referer" value="([^"]*)"/', $html, $matches );

		$this->assertStringContainsString( 'tab=settings', html_entity_decode( end( $matches[1] ) ) );
	}

	public function test_the_plugin_row_link_opens_the_settings_tab() {
		$links = $this->admin_page->add_action_link( array() );

		$this->assertStringContainsString( 'tab=settings', $links[0] );
	}

	public function test_render_page_falls_back_to_settings_for_an_unknown_tab() {
		$this->admin_page->register_settings();

		$this->assertStringContainsString( 'name="smtp_settings[host]"', $this->render( 'unknown' ) );
	}

	public function test_render_page_renders_nothing_without_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( '', $this->render() );
	}

	public function test_the_saved_password_is_never_printed() {
		( new Credentials() )->set( 'password', 'top-secret-password' );
		$this->admin_page->register_settings();

		$html = $this->render();

		$this->assertStringNotContainsString( 'top-secret-password', $html );
		$this->assertStringContainsString( 'A password is saved. Leave empty to keep it.', $html );
	}

	public function test_saved_values_are_escaped() {
		update_option( Settings::OPTION, array( 'host' => '"><script>alert(1)</script>' ) );
		$this->admin_page->register_settings();

		$html = $this->render( 'settings' );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
	}

	public function test_fields_locked_by_a_constant_are_disabled() {
		if ( ! defined( 'SMTP_TESTS_ADMIN_LOCKED' ) ) {
			define( 'SMTP_TESTS_ADMIN_LOCKED', 'value' );
		}

		add_filter(
			'smtp_providers',
			static function ( $providers ) {
				$providers['smtp']['fields']['host']['constant'] = 'SMTP_TESTS_ADMIN_LOCKED';

				return $providers;
			}
		);
		$this->admin_page->register_settings();

		$html = $this->render( 'settings' );

		$this->assertStringContainsString( 'SMTP_TESTS_ADMIN_LOCKED', $html );
		$this->assertMatchesRegularExpression( '/id="smtp-host"[^>]*disabled/s', $html );
	}

	public function test_render_page_shows_the_log_tab() {
		$html = $this->render( 'log' );

		$this->assertStringContainsString( 'No emails logged yet.', $html );
		$this->assertStringContainsString( 'never stored', $html );
	}

	public function test_render_page_shows_the_test_tab() {
		$html = $this->render( 'test' );

		$this->assertStringContainsString( 'value="smtp_send_test"', $html );
		$this->assertStringContainsString( 'is not configured yet', $html );
	}

	public function test_the_test_tab_hides_the_unconfigured_warning_once_configured() {
		update_option( Settings::OPTION, array( 'host' => 'smtp.example.com' ) );

		$this->assertStringNotContainsString( 'is not configured yet', $this->render( 'test' ) );
	}
}
