<?php
/**
 * Tests for the Privacy class.
 *
 * @package smtp
 */

use WordPress\SMTP\Log_Repository;
use WordPress\SMTP\Privacy;
use WordPress\SMTP\Test_Utils\Traits\With_Log_Table;

class Privacy_Tests extends WP_UnitTestCase {
	use With_Log_Table;

	/**
	 * @var Log_Repository
	 */
	private $repository;

	/**
	 * @var Privacy
	 */
	private $privacy;

	public function set_up() {
		parent::set_up();

		$this->create_log_table();

		$this->repository = new Log_Repository();
		$this->privacy    = new Privacy( $this->repository );
	}

	private function log_to( string $recipient, string $subject = 'Subject' ) {
		$this->repository->insert(
			array(
				'recipient' => $recipient,
				'subject'   => $subject,
				'status'    => 'sent',
				'error'     => '',
				'provider'  => 'smtp',
			)
		);
	}

	public function test_add_hooks_registers_the_privacy_hooks() {
		$this->privacy->add_hooks();

		$this->assertSame( 10, has_filter( 'wp_privacy_personal_data_exporters', array( $this->privacy, 'register_exporter' ) ) );
		$this->assertSame( 10, has_filter( 'wp_privacy_personal_data_erasers', array( $this->privacy, 'register_eraser' ) ) );
		$this->assertSame( 10, has_action( 'admin_init', array( $this->privacy, 'add_policy_content' ) ) );
	}

	public function test_the_plugin_registers_the_exporter_and_eraser() {
		$this->assertArrayHasKey( 'smtp', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'smtp', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
	}

	public function test_export_returns_the_entries_sent_to_the_address() {
		$this->log_to( 'jane@example.com', 'First' );
		$this->log_to( 'John Doe <john@example.com>, Jane <jane@example.com>', 'Second' );
		$this->log_to( 'john@example.com', 'Not hers' );

		$export = $this->privacy->export( 'jane@example.com', 1 );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 2, $export['data'] );
		$this->assertSame( 'smtp-email-log', $export['data'][0]['group_id'] );

		$subjects = array();
		foreach ( $export['data'] as $item ) {
			$values     = wp_list_pluck( $item['data'], 'value', 'name' );
			$subjects[] = $values['Subject'];
		}
		$this->assertSame( array( 'First', 'Second' ), $subjects );
	}

	public function test_addresses_must_match_a_whole_recipient() {
		$this->log_to( 'jbob@example.com' );
		$this->log_to( 'bob@example.com.evil' );
		$this->log_to( 'a@example.org,bob@example.com' );
		$this->log_to( 'bob@example.com, a@example.org' );
		$this->log_to( 'a@example.org, bob@example.com, c@example.org' );
		$this->log_to( 'BOB@example.com' );

		$this->assertCount( 4, $this->privacy->export( 'bob@example.com', 1 )['data'] );
	}

	public function test_like_wildcards_in_the_address_are_literal() {
		$this->log_to( 'bob@example.com' );

		$this->assertCount( 0, $this->privacy->export( 'b_b@example.com', 1 )['data'] );
	}

	public function test_export_is_paginated() {
		for ( $i = 0; $i < Privacy::PER_PAGE + 1; $i++ ) {
			$this->log_to( 'jane@example.com' );
		}

		$first  = $this->privacy->export( 'jane@example.com', 1 );
		$second = $this->privacy->export( 'jane@example.com', 2 );

		$this->assertCount( Privacy::PER_PAGE, $first['data'] );
		$this->assertFalse( $first['done'] );
		$this->assertCount( 1, $second['data'] );
		$this->assertTrue( $second['done'] );
	}

	public function test_an_invalid_address_exports_nothing() {
		$this->log_to( 'not-an-email' );

		$export = $this->privacy->export( 'not-an-email', 1 );

		$this->assertSame( array(), $export['data'] );
		$this->assertTrue( $export['done'] );
	}

	public function test_erase_removes_only_the_entries_of_the_address() {
		$this->log_to( 'jane@example.com' );
		$this->log_to( 'Jane <jane@example.com>, john@example.com' );
		$this->log_to( 'john@example.com', 'Kept' );

		$result = $this->privacy->erase( 'jane@example.com', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( array( 'Kept' ), wp_list_pluck( $this->get_logged_entries(), 'subject' ) );
	}

	public function test_erase_works_through_several_pages() {
		for ( $i = 0; $i < Privacy::PER_PAGE + 5; $i++ ) {
			$this->log_to( 'jane@example.com' );
		}

		$page = 1;
		do {
			$result = $this->privacy->erase( 'jane@example.com', $page++ );
		} while ( ! $result['done'] && $page < 10 );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 0, $this->get_logged_entries() );
	}

	public function test_erase_reports_nothing_removed_without_entries() {
		$result = $this->privacy->erase( 'nobody@example.com', 1 );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_policy_text_mentions_the_retention_period() {
		$this->assertStringContainsString( '30 days', $this->privacy->get_policy_text() );

		add_filter(
			'smtp_log_retention_days',
			static function () {
				return 7;
			}
		);

		$this->assertStringContainsString( '7 days', $this->privacy->get_policy_text() );
	}

	public function test_policy_text_without_retention() {
		add_filter( 'smtp_log_retention_days', '__return_zero' );

		$this->assertStringContainsString( 'not deleted automatically', $this->privacy->get_policy_text() );
	}

	public function test_add_policy_content_adds_the_text_to_the_guide() {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';

		// wp_add_privacy_policy_content() only works in wp-admin, while admin_init runs.
		set_current_screen( 'dashboard' );
		$GLOBALS['wp_current_filter'][] = 'admin_init';
		$this->privacy->add_policy_content();
		array_pop( $GLOBALS['wp_current_filter'] );
		set_current_screen( 'front' );

		$names = wp_list_pluck( WP_Privacy_Policy_Content::get_suggested_policy_text(), 'plugin_name' );
		$this->assertContains( 'SMTP', $names );
	}
}
