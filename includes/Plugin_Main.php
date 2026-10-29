<?php
/**
 * Class WordPress\SMTP\Plugin_Main
 *
 * @package smtp
 */

namespace WordPress\SMTP;

use WordPress\SMTP\Admin\Admin_Page;
use WordPress\SMTP\Admin\Test_Email;

/**
 * Main class for the plugin.
 *
 * @since 0.1.0
 */
class Plugin_Main {

	/**
	 * Name of the cron event that purges old log entries.
	 *
	 * @since 0.1.0
	 */
	const PURGE_HOOK = 'smtp_purge_logs';

	/**
	 * Path to the main plugin file.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $main_file;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $main_file Absolute path to the main plugin file.
	 */
	public function __construct( string $main_file ) {
		$this->main_file = $main_file;
	}

	/**
	 * Registers the plugin hooks.
	 *
	 * @since 0.1.0
	 */
	public function add_hooks() {
		$registry    = new Provider_Registry();
		$credentials = new Credentials();
		$settings    = new Settings( $registry, $credentials );
		$repository  = new Log_Repository();

		$migration = new Migration( $credentials );
		$migration->add_hooks();

		$mailer = new Mailer( $settings, $registry );
		$mailer->add_hooks();

		$logger = new Logger( $repository, $settings );
		$logger->add_hooks();

		add_action( 'admin_init', array( $repository, 'maybe_upgrade' ) );
		add_action( 'init', array( $this, 'schedule_purge' ) );

		if ( is_admin() ) {
			$admin = new Admin_Page( $settings, $registry, $repository, $this->main_file );
			$admin->add_hooks();

			$test_email = new Test_Email( $settings );
			$test_email->add_hooks();
		}
	}

	/**
	 * Ensures the daily log purge event is scheduled.
	 *
	 * @since 0.1.0
	 */
	public function schedule_purge() {
		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/**
	 * Runs on plugin activation.
	 *
	 * @since 0.1.0
	 */
	public static function activate() {
		( new Log_Repository() )->maybe_upgrade();
		( new Migration( new Credentials() ) )->maybe_migrate();

		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * @since 0.1.0
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::PURGE_HOOK );
	}
}
