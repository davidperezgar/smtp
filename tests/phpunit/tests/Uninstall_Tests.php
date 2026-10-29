<?php
/**
 * Tests for the uninstall routine.
 *
 * @package smtp
 */

use WordPress\SMTP\Credentials;
use WordPress\SMTP\Log_Repository;
use WordPress\SMTP\Migration;
use WordPress\SMTP\Plugin_Main;
use WordPress\SMTP\Settings;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;

class Uninstall_Tests extends WP_UnitTestCase {
	use With_Log_Table;

	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'smtp/smtp.php' );
		}

		require TESTS_PLUGIN_DIR . '/uninstall.php';
	}

	public function test_uninstall_removes_options_cron_and_table() {
		global $wpdb;

		$this->create_log_table();
		update_option( Settings::OPTION, array( 'host' => 'smtp.example.com' ) );
		( new Credentials() )->set( 'password', 'secret' );
		update_option( Migration::LEGACY_OPTION, array( 'host' => 'smtp.example.com' ) );
		update_option( Migration::DONE_OPTION, '1' );
		update_option( Migration::NOTICE_OPTION, Migration::NOTICE_MIGRATED );
		( new Log_Repository() )->insert(
			array(
				'recipient' => 'to@example.org',
				'subject'   => 'Subject',
				'status'    => 'sent',
				'error'     => '',
				'provider'  => 'smtp',
			)
		);
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', Plugin_Main::PURGE_HOOK );

		$queries = array();
		add_filter(
			'query',
			static function ( $query ) use ( &$queries ) {
				$queries[] = trim( $query );

				return $query;
			}
		);

		$this->run_uninstall();

		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Credentials::OPTION ) );
		$this->assertFalse( get_option( Log_Repository::DB_VERSION_OPTION ) );
		$this->assertFalse( get_option( Migration::LEGACY_OPTION ) );
		$this->assertFalse( get_option( Migration::DONE_OPTION ) );
		$this->assertFalse( get_option( Migration::NOTICE_OPTION ) );
		$this->assertFalse( wp_next_scheduled( Plugin_Main::PURGE_HOOK ) );

		$this->assertNotEmpty(
			preg_grep( '/^DROP (TEMPORARY )?TABLE IF EXISTS ' . preg_quote( $wpdb->prefix, '/' ) . 'smtp_logs$/', $queries )
		);
	}
}
