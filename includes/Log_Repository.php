<?php
/**
 * Class WordPress\SMTP\Log_Repository
 *
 * @package smtp
 */

namespace WordPress\SMTP;

// The email log lives in its own table, so direct queries are expected here. The table name is
// built from the trusted `$wpdb->prefix` and every dynamic value goes through `$wpdb->prepare()`.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

/**
 * Stores and queries the email log.
 *
 * @since 0.1.0
 */
class Log_Repository {

	/**
	 * Current version of the log table schema.
	 *
	 * @since 0.1.0
	 */
	const DB_VERSION = '1';

	/**
	 * Name of the option that stores the installed schema version.
	 *
	 * @since 0.1.0
	 */
	const DB_VERSION_OPTION = 'smtp_db_version';

	/**
	 * Returns the full table name.
	 *
	 * @since 0.1.0
	 *
	 * @return string Table name.
	 */
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'smtp_logs';
	}

	/**
	 * Creates or updates the table when the stored schema version is outdated.
	 *
	 * @since 0.1.0
	 */
	public function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $this->get_table_name();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				recipient text NOT NULL,
				subject text NOT NULL,
				status varchar(10) NOT NULL,
				error text NOT NULL,
				provider varchar(50) NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Inserts a log entry.
	 *
	 * @since 0.1.0
	 *
	 * @param array $entry Entry with `recipient`, `subject`, `status`, `error` and `provider`.
	 */
	public function insert( array $entry ) {
		global $wpdb;

		$wpdb->insert(
			$this->get_table_name(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'recipient'  => $entry['recipient'],
				'subject'    => $entry['subject'],
				'status'     => $entry['status'],
				'error'      => $entry['error'],
				'provider'   => $entry['provider'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Returns a page of log entries, newest first.
	 *
	 * @since 0.1.0
	 *
	 * @param string $status   Status to filter by, or an empty string for all.
	 * @param int    $per_page Entries per page.
	 * @param int    $offset   Offset.
	 * @return array[] Entries.
	 */
	public function get_items( string $status, int $per_page, int $offset ): array {
		global $wpdb;

		$table = $this->get_table_name();

		if ( '' !== $status ) {
			$query = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d",
				$status,
				$per_page,
				$offset
			);
		} else {
			$query = $wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			);
		}

		$items = $wpdb->get_results( $query, ARRAY_A );

		return is_array( $items ) ? $items : array();
	}

	/**
	 * Counts log entries.
	 *
	 * @since 0.1.0
	 *
	 * @param string $status Status to filter by, or an empty string for all.
	 * @return int Number of entries.
	 */
	public function count( string $status = '' ): int {
		global $wpdb;

		$table = $this->get_table_name();

		if ( '' !== $status ) {
			$query = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status );
		} else {
			$query = "SELECT COUNT(*) FROM {$table}";
		}

		return (int) $wpdb->get_var( $query );
	}

	/**
	 * Deletes entries older than the given number of days.
	 *
	 * @since 0.1.0
	 *
	 * @param int $days Number of days to keep.
	 */
	public function purge( int $days ) {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$table  = $this->get_table_name();

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}
}
