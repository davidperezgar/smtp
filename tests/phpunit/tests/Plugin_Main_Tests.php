<?php
/**
 * Tests for the Plugin_Main class.
 *
 * @package smtp
 */

use WordPress\SMTP\Plugin_Main;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;

class Plugin_Main_Tests extends WP_UnitTestCase {
	use With_Log_Table;

	public function set_up() {
		parent::set_up();

		wp_clear_scheduled_hook( Plugin_Main::PURGE_HOOK );
	}

	public function test_plugin_registers_mail_hooks() {
		$this->assertNotFalse( has_action( 'phpmailer_init' ) );
		$this->assertNotFalse( has_filter( 'wp_mail_from' ) );
		$this->assertNotFalse( has_filter( 'wp_mail_from_name' ) );
	}

	public function test_plugin_registers_log_hooks() {
		$this->assertNotFalse( has_action( 'wp_mail_succeeded' ) );
		$this->assertNotFalse( has_action( 'wp_mail_failed' ) );
		$this->assertNotFalse( has_action( Plugin_Main::PURGE_HOOK ) );
	}

	public function test_schedule_purge_schedules_a_daily_event() {
		$plugin_main = new Plugin_Main( WP_SMTP_MAIN_FILE );

		$this->assertFalse( wp_next_scheduled( Plugin_Main::PURGE_HOOK ) );

		$plugin_main->schedule_purge();

		$this->assertNotFalse( wp_next_scheduled( Plugin_Main::PURGE_HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( Plugin_Main::PURGE_HOOK ) );
	}

	public function test_schedule_purge_does_not_duplicate_the_event() {
		$plugin_main = new Plugin_Main( WP_SMTP_MAIN_FILE );

		$plugin_main->schedule_purge();
		$first = wp_next_scheduled( Plugin_Main::PURGE_HOOK );
		$plugin_main->schedule_purge();

		$this->assertSame( $first, wp_next_scheduled( Plugin_Main::PURGE_HOOK ) );
	}

	public function test_activate_creates_the_table_and_schedules_the_purge() {
		Plugin_Main::activate();

		$this->assertNotFalse( wp_next_scheduled( Plugin_Main::PURGE_HOOK ) );
		$this->assertSame( '1', get_option( 'smtp_db_version' ) );
	}

	public function test_deactivate_clears_the_scheduled_purge() {
		Plugin_Main::activate();
		Plugin_Main::deactivate();

		$this->assertFalse( wp_next_scheduled( Plugin_Main::PURGE_HOOK ) );
	}
}
