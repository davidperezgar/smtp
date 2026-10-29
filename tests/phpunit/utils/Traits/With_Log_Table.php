<?php
/**
 * Trait WordPress\SMTP\Test_Utils\Traits\With_Log_Table
 *
 * @package smtp
 */

namespace WordPress\SMTP\Test_Utils\Traits;

use WordPress\SMTP\Log_Repository;

trait With_Log_Table {

	/**
	 * Creates the log table. The WordPress test suite turns it into a temporary table.
	 */
	protected function create_log_table() {
		( new Log_Repository() )->maybe_upgrade();
	}

	/**
	 * Returns the logged entries, oldest first.
	 */
	protected function get_logged_entries(): array {
		global $wpdb;

		$table = ( new Log_Repository() )->get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
	}
}
