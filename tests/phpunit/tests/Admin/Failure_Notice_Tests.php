<?php
/**
 * Tests for the Failure_Notice class.
 *
 * @package smtp
 */

use WordPress\SMTP\Admin\Failure_Notice;
use WordPress\SMTP\Log_Repository;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;

class Failure_Notice_Tests extends WP_UnitTestCase {
	use With_Log_Table;

	/**
	 * @var Log_Repository
	 */
	private $repository;

	/**
	 * @var Failure_Notice
	 */
	private $notice;

	public function set_up() {
		parent::set_up();

		$this->create_log_table();

		$this->repository = new Log_Repository();
		$this->notice     = new Failure_Notice( $this->repository );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		unset( $_REQUEST['_wpnonce'], $_SERVER['HTTP_REFERER'] );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	private function log( string $status, int $age_in_seconds = 0 ) {
		global $wpdb;

		$this->repository->insert(
			array(
				'recipient' => 'to@example.org',
				'subject'   => 'Subject',
				'status'    => $status,
				'error'     => 'failed' === $status ? 'Boom' : '',
				'provider'  => 'smtp',
			)
		);

		if ( $age_in_seconds > 0 ) {
			$wpdb->update(
				$this->repository->get_table_name(),
				array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $age_in_seconds ) ),
				array( 'id' => $wpdb->insert_id )
			);
		}
	}

	private function render(): string {
		ob_start();
		$this->notice->render();

		return ob_get_clean();
	}

	private function dismiss(): string {
		$_REQUEST['_wpnonce'] = wp_create_nonce( Failure_Notice::DISMISS_ACTION );

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( $location );
			}
		);

		try {
			$this->notice->handle_dismiss();
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		}

		return '';
	}

	public function test_add_hooks_registers_the_hooks() {
		$this->notice->add_hooks();

		$this->assertSame( 10, has_action( 'admin_notices', array( $this->notice, 'render' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_' . Failure_Notice::DISMISS_ACTION, array( $this->notice, 'handle_dismiss' ) ) );
	}

	public function test_no_notice_without_failures() {
		$this->log( 'sent' );

		$this->assertSame( '', $this->render() );
	}

	public function test_notice_counts_the_failures_of_the_last_day() {
		$this->log( 'failed' );
		$this->log( 'failed', HOUR_IN_SECONDS );
		$this->log( 'failed', 2 * DAY_IN_SECONDS );
		$this->log( 'sent' );

		$html = $this->render();

		$this->assertStringContainsString( '2 emails failed to send in the last 24 hours.', $html );
		$this->assertStringContainsString( 'status=failed', $html );
		$this->assertStringContainsString( 'tab=log', $html );
		$this->assertStringContainsString( 'action=' . Failure_Notice::DISMISS_ACTION, $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
	}

	public function test_notice_uses_the_singular_for_one_failure() {
		$this->log( 'failed' );

		$this->assertStringContainsString( '1 email failed to send', $this->render() );
	}

	public function test_old_failures_do_not_show_the_notice() {
		$this->log( 'failed', 2 * DAY_IN_SECONDS );

		$this->assertSame( '', $this->render() );
	}

	public function test_the_period_can_be_changed_with_a_filter() {
		$this->log( 'failed', 2 * DAY_IN_SECONDS );

		add_filter(
			'smtp_failure_notice_period',
			static function () {
				return WEEK_IN_SECONDS;
			}
		);

		$this->assertStringContainsString( 'in the last 168 hours', $this->render() );
	}

	public function test_no_notice_for_users_without_the_capability() {
		$this->log( 'failed' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->render() );
	}

	public function test_no_notice_on_the_smtp_screen() {
		$this->log( 'failed' );
		set_current_screen( 'tools_page_smtp' );

		$this->assertSame( '', $this->render() );
	}

	public function test_dismiss_hides_the_notice_until_a_new_failure() {
		$this->log( 'failed', MINUTE_IN_SECONDS );
		$_SERVER['HTTP_REFERER'] = admin_url( 'index.php' );

		$location = $this->dismiss();

		$this->assertSame( admin_url( 'index.php' ), $location );
		$this->assertSame( '', $this->render() );

		$this->log( 'failed' );
		// The new failure must be newer than the dismissal, which has a one second resolution.
		update_user_meta( get_current_user_id(), Failure_Notice::DISMISSED_META, gmdate( 'Y-m-d H:i:s', time() - 30 ) );

		$this->assertStringContainsString( '1 email failed to send', $this->render() );
	}

	public function test_dismiss_is_per_user() {
		$this->log( 'failed', MINUTE_IN_SECONDS );
		$this->dismiss();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertStringContainsString( '1 email failed to send', $this->render() );
	}

	public function test_dismiss_rejects_a_missing_nonce() {
		$this->expectException( WPDieException::class );

		$this->notice->handle_dismiss();
	}

	public function test_dismiss_rejects_users_without_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Failure_Notice::DISMISS_ACTION );

		try {
			$this->notice->handle_dismiss();
		} catch ( WPDieException $exception ) {
			$this->assertSame( '', get_user_meta( get_current_user_id(), Failure_Notice::DISMISSED_META, true ) );
			return;
		}

		$this->fail( 'The request should have been rejected.' );
	}
}
